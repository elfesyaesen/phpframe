<?php

declare(strict_types=1);

namespace Api\Services;

use Api\Exceptions\DuplicateNameException;
use Api\Models\AuthModel;
use Api\Models\PasswordResetModel;
use Api\Models\UserModel;
use System\Config\AppConfig;
use System\Config\AuthConfig;
use System\Database\Database;
use System\Exceptions\BadRequestException;
use System\Exceptions\InternalServerException;
use System\Exceptions\NotFoundException;
use System\Exceptions\ValidationException;
use System\Security\PasswordHasher;
use System\Translation\Contract\TranslatorInterface;

/**
 * Kullanıcı hesabı kullanım senaryoları: kayıt, profil, parola, hesap silme.
 *
 * Katman sözleşmesi için bkz. docs/api-layer.md.
 */
final class UserService
{
    /** `updateProfile` ile değiştirilebilen alanlar. */
    private const EDITABLE_FIELDS = ['firstname', 'lastname', 'phone'];

    public function __construct(
        private readonly UserModel $users,
        private readonly AuthModel $auth,
        private readonly PasswordResetModel $resets,
        private readonly AuthService $sessions,
        private readonly MailService $mail,
        private readonly PasswordHasher $passwords,
        private readonly Database $database,
        private readonly TranslatorInterface $translator,
        private readonly AppConfig $app,
        private readonly AuthConfig $auth_config,
    ) {
    }

    /**
     * Yeni kullanıcı oluşturur ve doğrudan oturum açar.
     *
     * @param array<string, mixed> $validated
     *
     * @throws ValidationException e-posta/kullanıcı adı çakışırsa
     */
    public function register(array $validated): TokenBundle
    {
        // Parola hash'leme TEK politikadan geçer (PasswordHasher). Eskiden
        // burada `PASSWORD_DEFAULT`, parola değiştirmede `PASSWORD_BCRYPT`
        // kullanılıyordu — aynı sütunda iki algoritma.
        $validated['password'] = $this->passwords->hash((string) $validated['password']);

        try {
            $uuid = $this->users->storeUser($validated);
        } catch (DuplicateNameException $e) {
            $key     = $e->field === 'email' ? 'user.email_taken' : 'user.username_taken';
            $message = $this->translator->trans($key);

            throw new ValidationException([$e->field => [$message]], $message, previous: $e);
        }

        $user = $this->auth->getUserByUuid($uuid);

        if ($user === false) {
            // INSERT başarılı ama okuma başarısız — tutarsız durum.
            throw new InternalServerException($this->translator->trans('user.register_failed'));
        }

        return $this->sessions->startSession($user);
    }

    /**
     * Kullanıcı profili.
     *
     * @return array<string, mixed>
     * @throws NotFoundException
     */
    public function profile(string $userUuid): array
    {
        $profile = $this->users->getProfile($userUuid);

        if ($profile === false) {
            throw new NotFoundException($this->translator->trans('user.not_found'));
        }

        return $profile;
    }

    /**
     * PATCH semantiği: yalnızca GÖVDEDE BULUNAN alanlar güncellenir.
     *
     * `$present` ham gövdedir; hangi alanların GÖNDERİLDİĞİNİ ondan anlarız.
     * `$validated` ise doğrulanmış değerleri taşır. İkisi ayrı olmak zorunda:
     * `null` göndermek ("alanı temizle") ile alanı hiç göndermemek ("dokunma")
     * farklı isteklerdir ve yalnızca ham gövde bu ayrımı taşır.
     *
     * @param array<string, mixed> $present
     * @param array<string, mixed> $validated
     * @return array<string, mixed> güncellenmiş profil
     *
     * @throws ValidationException  hiçbir düzenlenebilir alan gönderilmemişse
     * @throws NotFoundException    kullanıcı yoksa
     */
    public function updateProfile(string $userUuid, array $present, array $validated): array
    {
        $set = [];

        foreach (self::EDITABLE_FIELDS as $field) {
            if (array_key_exists($field, $present)) {
                $set[$field] = $validated[$field] ?? null;
            }
        }

        if ($set === []) {
            $message = $this->translator->trans('user.profile.no_changes');

            throw new ValidationException(['profile' => [$message]], $message);
        }

        // NOT: dönüş DEĞERİ kontrol EDİLMEZ ve bu bilinçlidir.
        // `updateProfile` başarıyı `rowCount() > 0` ile ölçüyor; aynı değerler
        // gönderildiğinde MySQL 0 satır bildirir. Bunu hata saymak, "adımı
        // zaten olduğu değere ayarla" isteğini 500'e çevirirdi. Kullanıcının
        // var olup olmadığı aşağıdaki okuma ile zaten anlaşılır.
        $this->users->updateProfile($userUuid, $set);

        return $this->profile($userUuid);
    }

    /**
     * Parola değiştirir ve TÜM oturumları kapatır.
     *
     * @throws BadRequestException mevcut parola hatalıysa
     */
    public function changePassword(string $userUuid, string $currentPassword, string $newPassword): void
    {
        $this->assertPassword($userUuid, $currentPassword);

        $this->database->transaction(function () use ($userUuid, $newPassword): void {
            if (!$this->users->updatePassword($userUuid, $this->passwords->hash($newPassword))) {
                throw new InternalServerException($this->translator->trans('user.password_update_failed'));
            }

            // OTURUM İPTALİ — eskiden YAPILMIYORDU.
            //
            // `deleteAccount()` token'ları siliyordu ama `changePassword()`
            // silmiyordu. Sonuç: parolası çalınmış bir kullanıcı parolasını
            // değiştirse bile, saldırganın elindeki access/refresh token
            // ÇALIŞMAYA DEVAM EDİYORDU. Parola değişimi, oturum iptalinin en
            // yaygın sebebidir.
            $this->auth->removeRefreshToken($userUuid);
        });
    }

    /**
     * Parola sıfırlama LİNKİ ve OTP KODU gönderir (adım 1/2).
     *
     * ─────────────────────────────────────────────────────────────────────
     * NEDEN İKİ TESLİM BİÇİMİ: API mobil-only. Link'in çalışması için ya bir
     * web sayfası ya da universal/app link kurulumu gerekir; kod ise hiçbir
     * şey gerektirmez. Mail ikisini de taşır, kullanıcı hangisi işine
     * geliyorsa onu kullanır:
     *
     *   • Link → mail'i telefonda açan kullanıcı için tek dokunuş
     *   • Kod  → mail'i bilgisayarda açan, uygulama kurulu olmayan, ya da
     *            link'i tıklanamaz gösteren bir mail istemcisi kullanan
     *            kullanıcı için tek çıkış yolu
     *
     * İkisi AYNI SATIRDA saklanır. Ayrı satırlar olsaydı biri tüketilirken
     * diğerini de silmek gerekirdi ve o iki silme arasındaki pencerede ikinci
     * sır hâlâ geçerli olurdu.
     *
     * Kod 6 hane, yani 10^6 olasılık — kaba kuvvete açık. Bunu kapatan şey
     * `attempts` sayacı; ayrıntısı `confirmPasswordResetWithCode()` ve
     * `PasswordResetModel::registerFailedAttempt()` yorumlarında.
     * ─────────────────────────────────────────────────────────────────────
     *
     * ─────────────────────────────────────────────────────────────────────
     * TASARIM — parola e-postalanMAZ, tek kullanımlık sır gönderilir.
     *
     * Eski akış yeni bir parola üretip e-postayla yolluyordu. İki sorunu
     * vardı ve ikincisi kapatılamıyordu:
     *
     *  1. Parola e-posta arşivinde KALICI ve düz metin. Posta hesabı ele
     *     geçirilirse uygulama hesabı da gider — kullanıcı parolasını sonra
     *     değiştirmiş olsa bile eski mesaj hâlâ orada.
     *
     *  2. "Mail teslim edildi ama commit patladı" penceresi. Transaction'la
     *     daraltılmıştı ama yok edilemiyordu: e-posta teslimi geri alınamaz,
     *     dolayısıyla kullanıcıya kendisinin olmayan bir parola gitmiş
     *     olabiliyordu.
     *
     * Token akışı ikincisini TAMAMEN yok eder: link kullanılmadıkça hesap
     * DEĞİŞMEZ. Mail gidip DB patlarsa token yazılmamıştır, link ölüdür,
     * kullanıcı eski parolasıyla girmeye devam eder — zararsız.
     *
     * Sıralama bu yüzden "önce DB, sonra mail": token yazılamazsa mail hiç
     * gönderilmez. Mail gönderilemezse token boşuna durur ve süresi doler.
     *
     * Token'ın kendisi DB'ye YAZILMAZ, yalnızca SHA-256 hash'i. Neden
     * `password_hash` değil: token 256 bit CSPRNG çıktısı, yani sözlük
     * saldırısına konu değil — yavaş hash'in koruduğu şey burada yok, ama
     * sabit uzunluklu hash `token_hash` üzerinde UNIQUE indeks ve tek
     * sorguda arama sağlar. Aynı ilke `users_token` tablosunda da geçerli.
     *
     * Kullanıcı başına ÖNCE TEMİZLİK: yeni talep eski linkleri öldürür,
     * böylece aynı anda birden fazla geçerli sıfırlama linki dolaşmaz.
     * ─────────────────────────────────────────────────────────────────────
     *
     * ── E-POSTA NUMARALANDIRMA KORUMASI ─────────────────────────────────
     *
     * Metot `void`: çağıran hiçbir koşulda "adres kayıtlı mıydı" bilgisini
     * öğrenemez. Koruma tip sistemiyle zorunlu kılınmıştır.
     *
     * MAIL BAŞARISIZLIĞI DA SIZDIRMAZ — burası düzeltilen bir açıktı. Eski
     * davranış: bilinmeyen adres → 200, bilinen adres + mail hatası → 500.
     * Saldırgan yalnızca durum koduna bakarak kayıtlı e-postaları
     * numaralandırabiliyordu.
     */
    public function requestPasswordReset(string $email): void
    {
        $user = $this->users->getUserByEmail($email);

        if ($user === false) {
            return;
        }

        $uuid = (string) $user['uuid'];

        // 256 bit CSPRNG → 64 karakter hex. URL'de güvenle taşınır, kaçış
        // gerektirmez.
        $token = bin2hex(random_bytes(32));

        // 6 hane, BAŞTAKİ SIFIRLAR KORUNARAK.
        //
        // `random_int(100000, 999999)` yazmak kolaydı ama uzayı 900.000'e
        // düşürür ve "0" ile başlayan kodları imkânsız kılar — saldırgan bunu
        // bilir ve denemez. `str_pad` ile tam 10^6 kullanılır.
        //
        // `rand`/`mt_rand` DEĞİL: `random_int` CSPRNG'dir. Sıfırlama kodu
        // tam hesap devralma yetkisi taşır, tahmin edilebilir bir üreteç
        // tüm korumayı anlamsız kılar (`PasswordHasher::generate()` içinde
        // düzeltilen `str_shuffle` hatasıyla aynı sınıf).
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        try {
            $this->database->transaction(function () use ($uuid, $token, $code): void {
                // Eski kayıtları öldür — kullanıcı başına tek geçerli link+kod.
                $this->resets->deleteForUser($uuid);
                $this->resets->store(
                    $uuid,
                    hash('sha256', $token),
                    hash('sha256', $code),
                    time() + $this->auth_config->passwordResetExpire
                );
            });
        } catch (\Throwable $e) {
            // Kayıt yazılamadıysa mail GÖNDERİLMEZ: ölü bir link/kod yollamak,
            // kullanıcıyı çalışmayan bir akışa sokup destek talebi üretir.
            throw new InternalServerException(
                $this->translator->trans('user.password_reset_failed'),
                previous: $e
            );
        }

        // Link'i `AuthConfig` kurar: `PASSWORD_RESET_URL` mutlak bir URL ise
        // (universal link ya da custom scheme) `APP_URL` ön eki EKLENMEZ.
        // Bu ayrım olmadan "myapp://reset" sessizce bozulurdu.
        $url = $this->auth_config->resetLink($this->app, $token);

        // Mail başarısızlığı YUTULUR — yukarıdaki numaralandırma notu.
        // `MailService` zaten `error_log`'a yazar. Kayıt boşuna durur ve
        // süresi doler; hesap etkilenmez.
        $this->mail->sendPasswordResetLink(
            (string) $user['email'],
            $url,
            $code,
            (int) ceil($this->auth_config->passwordResetExpire / 60)
        );
    }

    /**
     * LİNK yolu: token'ı tüketir ve yeni parolayı yazar (adım 2/2).
     *
     * Token 256 bit CSPRNG olduğu için kaba kuvvet denemesi anlamsızdır;
     * bu yüzden burada deneme sayacı YOKTUR. Kod yolunun neden sayaca
     * ihtiyaç duyduğu `confirmPasswordResetWithCode()` içinde açıklanıyor.
     *
     * `NotFoundException` (404) hem geçersiz hem süresi geçmiş token için
     * verilir: ayrımı istemciye sızdırmak, geçerli token uzayını daraltmaya
     * yarardı.
     *
     * @throws NotFoundException token geçersiz veya süresi geçmişse
     */
    public function confirmPasswordReset(string $token, string $newPassword): void
    {
        $record = $this->resets->findValid(hash('sha256', $token));

        if ($record === null) {
            throw new NotFoundException($this->translator->trans('user.reset_token_invalid'));
        }

        $this->applyReset((string) $record['user_uuid'], $newPassword);
    }

    /**
     * OTP yolu: 6 haneli kodu tüketir ve yeni parolayı yazar (adım 2/2).
     *
     * ─────────────────────────────────────────────────────────────────────
     * `$email` NEDEN GEREKLİ: kod tek başına aranamaz. `WHERE code_hash = ?`
     * diyen bir sorgu tüm kullanıcılar arasında eşleşme arardı, yani
     * saldırgan tek bir kod deneyerek O KODU BEKLEYEN HERHANGİ bir hesabı
     * ele geçirebilirdi — ve kullanıcı sayısı arttıkça başarı olasılığı da
     * artardı. E-posta, arama uzayını tek hesaba sabitler.
     *
     * ── DENEME BÜTÇESİ — kodun kaba kuvvete karşı tek savunması ─────────
     *
     * 6 hane = 10^6. Rota bazlı rate limit YETMEZ: IP döndürerek aşılır.
     * Bu yüzden sayaç kaydın kendisinde tutulur ve
     * `PASSWORD_RESET_MAX_ATTEMPTS` hatalı denemeden sonra kayıt SİLİNİR.
     * Saldırgan kaç IP kullanırsa kullansın o talep için bütçesi biter.
     *
     * Sayaç artırma TRANSACTION DIŞINDA yapılır ve bu kritik: hatalı
     * denemenin ardından exception fırlatılıyor, transaction içinde olsaydı
     * rollback sayacı da geri alır ve koruma tamamen kaybolurdu.
     *
     * Bütçe bitince LİNK DE ÖLÜR — kasıtlı. Kod üzerinde kaba kuvvet
     * görülüyorsa o talebin tamamı şüphelidir; kullanıcının yeniden talep
     * etmesi bir e-postaya bedel, yanlış tarafta hata yapmak hesaba bedel.
     *
     * ── SABİT ZAMANLI KARŞILAŞTIRMA ─────────────────────────────────────
     *
     * `hash_equals` kullanılır, `===` değil. PHP'nin string karşılaştırması
     * ilk farklı byte'ta çıkar; bu, doğru tahmin edilen ön eki ölçülebilir
     * bir zaman farkına çevirir ve 10^6 uzayını basamak basamak aranabilir
     * hale getirir. Karşılaştırılan şeyler hash olduğu için farklar zaten
     * ilk byte'larda dağılır, ama savunma girdinin şekline bağlı
     * bırakılmaz.
     *
     * ── AYNI 404 ────────────────────────────────────────────────────────
     *
     * Bilinmeyen e-posta, geçerli kaydı olmayan kullanıcı, yanlış kod ve
     * süresi geçmiş kayıt AYNI 404'ü döner. Ayrım sızdırılsaydı bu uç bir
     * e-posta numaralandırma aracına dönüşürdü — `requestPasswordReset()`
     * içindeki korumayı arka kapıdan boşa çıkarırdı.
     * ─────────────────────────────────────────────────────────────────────
     *
     * @throws NotFoundException e-posta/kod geçersiz, süresi geçmiş veya
     *                           deneme bütçesi bitmişse
     */
    public function confirmPasswordResetWithCode(string $email, string $code, string $newPassword): void
    {
        $invalid = fn(): NotFoundException => new NotFoundException(
            $this->translator->trans('user.reset_code_invalid')
        );

        $user = $this->users->getUserByEmail($email);

        if ($user === false) {
            throw $invalid();
        }

        $userUuid = (string) $user['uuid'];
        $record   = $this->resets->findValidForUser($userUuid);

        if ($record === null) {
            throw $invalid();
        }

        if (!hash_equals($record['code_hash'], hash('sha256', $code))) {
            // Transaction DIŞINDA — yukarıdaki gerekçe.
            $this->resets->registerFailedAttempt(
                $record['uuid'],
                $this->auth_config->passwordResetMaxAttempts
            );

            throw $invalid();
        }

        $this->applyReset($userUuid, $newPassword);
    }

    /**
     * Sıfırlamanın ortak son adımı — iki yol da buraya varır.
     *
     * ─────────────────────────────────────────────────────────────────────
     * Tek transaction, şu SIRAYLA:
     *
     *   1. parolayı yaz
     *   2. sıfırlama kayıtlarını sil  → tek kullanımlık
     *   3. oturumları kapat
     *
     * 2 ve 3 atlanamaz. Kayıt silinmezse link ve kod tekrar kullanılabilir
     * hale gelir; oturumlar kapatılmazsa parolayı ele geçirmiş saldırganın
     * elindeki access/refresh token ÇALIŞMAYA DEVAM eder — ki parola
     * sıfırlamanın en yaygın sebebi tam olarak budur. `changePassword()`
     * ile aynı gerekçe.
     *
     * Link ve kod yolunun bu adımı PAYLAŞMASI şart: ayrı ayrı yazılsaydı
     * birinde oturum iptalinin unutulması sessiz bir açık olurdu — kaynak
     * projede `changePassword`/`deleteAccount` arasında tam olarak bu
     * asimetri vardı.
     * ─────────────────────────────────────────────────────────────────────
     */
    private function applyReset(string $userUuid, string $newPassword): void
    {
        $hash = $this->passwords->hash($newPassword);

        $this->database->transaction(function () use ($userUuid, $hash): void {
            if (!$this->users->updatePassword($userUuid, $hash)) {
                throw new InternalServerException($this->translator->trans('user.password_update_failed'));
            }

            // Tek kullanımlık: kullanıcının TÜM sıfırlama kayıtları gider —
            // yani hem link hem kod ölür.
            $this->resets->deleteForUser($userUuid);

            // Parola değişti → eski oturumlar geçersiz.
            $this->auth->removeRefreshToken($userUuid);
        });
    }

    /**
     * Hesabı siler (soft delete) ve tüm oturumları kapatır.
     *
     * @throws BadRequestException mevcut parola hatalıysa
     */
    public function deleteAccount(string $userUuid, string $currentPassword): void
    {
        $this->assertPassword($userUuid, $currentPassword);

        $this->database->transaction(function () use ($userUuid): void {
            if (!$this->users->softDeleteUser($userUuid)) {
                throw new InternalServerException($this->translator->trans('user.delete_failed'));
            }

            // Eskiden bu çağrının dönüşü kontrol EDİLMİYORDU ve transaction
            // yoktu: silme başarılı, token temizliği başarısız olsaydı hesap
            // silinmiş ama oturumlar CANLI kalırdı.
            $this->auth->removeRefreshToken($userUuid);
        });
    }

    // ── Yardımcılar ───────────────────────────────────────────────

    /**
     * Mevcut parolayı doğrular.
     *
     * `changePassword` ve `deleteAccount` içinde BİREBİR AYNI dört satır
     * tekrarlanıyordu. Tek yerde olması, doğrulamanın birinde unutulması
     * ihtimalini ortadan kaldırır.
     *
     * @throws BadRequestException
     */
    private function assertPassword(string $userUuid, string $password): void
    {
        $hash = $this->users->getUserPassword($userUuid);

        // `getUserPassword` kullanıcı yoksa `false` döner. Bu durumda da AYNI
        // mesaj verilir: "kullanıcı yok" ile "parola yanlış" ayrımını istemciye
        // sızdırmak, silinmiş hesapları numaralandırmaya yarardı.
        if ($hash === false || $hash === '' || !$this->passwords->verify($password, $hash)) {
            // 400 KORUNUYOR (401 değil) — mevcut istemciler bu kodu bekliyor.
            throw new BadRequestException($this->translator->trans('user.current_password_wrong'));
        }
    }
}
