<?php

declare(strict_types=1);

namespace Api\Services;

use Api\Models\UserModel;
use System\Exceptions\InternalServerException;
use System\Exceptions\NotFoundException;
use System\Exceptions\ValidationException;
use System\Storage\FileStorageInterface;
use System\Storage\StorageException;
use System\Storage\UploadValidator;
use System\Translation\Contract\TranslatorInterface;
use Throwable;

/**
 * Kullanıcı avatarı: yükleme, silme, servis etme.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * NEDEN `UserService` İÇİNDE DEĞİL, AYRI:
 *
 * `FileStorageInterface` + `UploadValidator` bağımlılığı kod tabanında
 * YALNIZCA burada var ve `UserController`'ın 7 constructor bağımlılığının
 * asıl sebebi buydu. Avatar mantığı `UserService` içinde kalsaydı, her
 * `changePassword()` çağrısı depolama sürücüsünü de çözerdi.
 *
 * ── DEPOLAMA + VERİTABANI: TRANSACTION YOK, SIRALAMA VAR ────────────────
 *
 * Blob yazımı geri alınamaz — `Database::transaction()` bir dosya sistemi
 * yazmasını rollback edemez. Bu yüzden burada transaction DEĞİL, telafi
 * (compensation) kullanılır ve sıralama şöyle seçilir:
 *
 *   1. blob YAZILIR   (DB satırı anahtarı gerektirdiği için önce olmak zorunda)
 *   2. DB GÜNCELLENİR
 *   3. DB başarısızsa → yeni blob SİLİNİR (telafi)
 *   4. DB başarılıysa → eski blob silinir (temizlik)
 *
 * Kabul edilen başarısızlık modu YETİM BLOB'dur (adım 3'ün kendisi de
 * patlarsa). Kabul EDİLMEYEN mod, DB'nin var olmayan bir dosyayı işaret
 * etmesidir — kullanıcı avatarını göremez ve durum kendiliğinden düzelmez.
 *
 * Eski kodda bu telafi VARDI ama çalışmıyordu: `Storage->delete()` çağrıları
 * `try` bloklarının DIŞINDAYDI ve `jsonResponse()` `exit` ettiği için
 * "eski blob'u sil" satırına hiçbir zaman ulaşılamıyordu.
 * ─────────────────────────────────────────────────────────────────────────
 */
final class AvatarService
{
    /**
     * Kabul edilen MIME türleri.
     *
     * Eskiden `UserController::uploadAvatar()` içinde satır arasında duran
     * bir dizi literaliydi. Yükleme politikası bir DOMAIN kuralıdır; HTTP
     * katmanında tutulması, aynı kuralın ikinci bir uçta farklı yazılmasını
     * kolaylaştırırdı.
     */
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /** Azami dosya boyutu (5 MiB). */
    private const MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(
        private readonly UserModel $users,
        private readonly FileStorageInterface $storage,
        private readonly UploadValidator $validator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Avatarı yükler ve kullanıcıya bağlar.
     *
     * @param array<string, mixed> $file `$_FILES` girdisi
     *
     * @throws ValidationException     dosya yoksa, türü/boyutu kabul edilmezse
     * @throws InternalServerException blob veya DB yazımı başarısızsa
     */
    public function upload(string $userUuid, mixed $file): void
    {
        if (!is_array($file)) {
            throw new ValidationException(
                ['avatar' => [$this->translator->trans('user.avatar.required')]],
                $this->translator->trans('user.avatar.required'),
            );
        }

        $mime = $this->validateUpload($file);
        $key  = $this->buildKey($userUuid, $this->validator->extensionFor($mime));

        $oldKey = $this->users->getAvatarKey($userUuid);

        try {
            $this->storage->putFromUpload($key, $file);
        } catch (StorageException $e) {
            // `$e->getMessage()` İSTEMCİYE GİTMEZ. Eski kod onu doğrudan
            // gövdeye koyuyordu: çevrilmemiş ve dosya sistemi yolu gibi iç
            // ayrıntı sızdırabilen tek yerdi.
            throw new InternalServerException(
                $this->translator->trans('user.avatar.save_failed'),
                previous: $e,
            );
        }

        if (!$this->users->updateAvatarKey($userUuid, $key)) {
            $this->discard($key);

            throw new InternalServerException($this->translator->trans('user.avatar.save_failed'));
        }

        if ($oldKey !== null && $oldKey !== $key) {
            $this->discard($oldKey);
        }
    }

    /**
     * Avatarı kaldırır.
     *
     * @throws NotFoundException kullanıcının avatarı yoksa
     */
    public function remove(string $userUuid): void
    {
        $key = $this->requireKey($userUuid);

        // DB ÖNCE: satır temizlenmeden blob silinirse, DB var olmayan bir
        // dosyayı işaret eder ve `serveAvatar` 404 yerine bozuk yanıt üretir.
        // Bu sırada en kötü ihtimal yetim blob'dur.
        if (!$this->users->updateAvatarKey($userUuid, null)) {
            throw new InternalServerException($this->translator->trans('user.avatar.save_failed'));
        }

        $this->discard($key);
    }

    /**
     * Avatarı yanıt gövdesine akıtır.
     *
     * NOT: akıtma bir ÇIKTI işidir ve ideal olarak `Response` katmanına
     * aittir. Bugün `FileStorageInterface::stream()` başlıkları kendi yazıyor
     * (kod tabanındaki dördüncü, bildirilmemiş yanıt kanalı). Servisin
     * `Storage`'a delege etmesi, `Storage`'ı controller'dan uzak tutar;
     * `Response::exit` kaldırıldığında bu metot bir `StreamedResponse`
     * döndürecek şekilde değişir.
     *
     * @throws NotFoundException avatar kaydı yoksa veya dosya diskte yoksa
     */
    public function stream(string $userUuid): void
    {
        $key = $this->requireKey($userUuid);

        if (!$this->storage->exists($key)) {
            throw new NotFoundException($this->translator->trans('user.avatar.not_found'));
        }

        $this->storage->stream($key);
    }

    // ── Yardımcılar ───────────────────────────────────────────────

    /**
     * @param array<string, mixed> $file
     * @throws ValidationException
     */
    private function validateUpload(array $file): string
    {
        try {
            return $this->validator->validate($file, self::ALLOWED_MIMES, self::MAX_BYTES);
        } catch (StorageException $e) {
            // Doğrulayıcının mesajı ÇEVRİLMEMİŞTİR ve iç ayrıntı taşıyabilir;
            // istemciye çevrilmiş, sabit bir mesaj gider.
            throw new ValidationException(
                ['avatar' => [$this->translator->trans('user.avatar.required')]],
                $this->translator->trans('user.avatar.required'),
                previous: $e,
            );
        }
    }

    /**
     * Depolama anahtarı: `avatars/{userUuid}/{nonce}.{ext}`
     *
     * Nonce ZORUNLU: anahtar yalnızca kullanıcı uuid'inden türeseydi, yeni
     * avatar eskisinin üzerine yazardı ve CDN/tarayıcı önbelleği eski görseli
     * göstermeye devam ederdi. Her yükleme yeni bir anahtar üretir.
     */
    private function buildKey(string $userUuid, string $extension): string
    {
        return sprintf('avatars/%s/%s.%s', $userUuid, bin2hex(random_bytes(8)), $extension);
    }

    /**
     * @throws NotFoundException
     */
    private function requireKey(string $userUuid): string
    {
        $key = $this->users->getAvatarKey($userUuid);

        if ($key === null) {
            throw new NotFoundException($this->translator->trans('user.avatar.not_found'));
        }

        return $key;
    }

    /**
     * Blob'u en iyi çabayla siler.
     *
     * Silme başarısızlığı ASLA isteği düşürmez: bu çağrılar ya bir telafi
     * adımıdır (asıl hata zaten fırlatılacak) ya da temizliktir. Yetim bir
     * dosya kabul edilebilir; kullanıcıya "avatarınız güncellenemedi" demek
     * ise — güncellendiği hâlde — kabul edilemez.
     */
    private function discard(string $key): void
    {
        try {
            $this->storage->delete($key);
        } catch (Throwable) {
            // Sessizce geç — bkz. yukarıdaki gerekçe.
        }
    }
}
