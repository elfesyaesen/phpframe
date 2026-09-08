<?php
namespace Api\Models;

use System\Engine\BaseModel;
use System\Helpers\Uuid;
use PDOException;

/**
 * Oturum ve kimlik doğrulama VERİ ERİŞİMİ.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * BU SINIF ARTIK JWT BİLMİYOR.
 *
 * Eskiden `AuthConfig` (JWT sırrı), `Firebase\JWT\JWT` ve `Key` bağımlılıkları
 * vardı; hepsi tek bir `validateToken()` metodu içindi ve o metot
 * `AuthService::validate()`'in birebir kopyasıydı — token doğrulamasının iki
 * sahibi vardı. Metodun hiç çağıranı yoktu ve silindi.
 *
 * Ayrıca `AuthService::hashToken()`'ı STATİK çağırıyordu: model, servis
 * katmanına uzanıyordu ve bu bağımlılık değiştirilebilir değildi. Hash'ler
 * artık parametre olarak gelir.
 *
 * Kalan yüzey saf persistence: SQL + satır döndürme.
 * ─────────────────────────────────────────────────────────────────────────
 */
class AuthModel extends BaseModel
{

    public function authenticate(string $email, string $password): array|false
    {
        if (empty($email)) {
            return false;
        }

        $sql = "SELECT uuid, username, email, password, firstname, lastname, phone, created_at, updated_at
                FROM " . $this->prefix() . "user WHERE email = :email AND deleted_at IS NULL LIMIT 1";
        $statement = $this->pdo()->prepare($sql);
        $statement->execute(['email' => $email]);
        $user = $statement->fetch(\PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password'])) {
            return false;
        }
        unset($user['password']);
        return $user;
    }

    public function getUserByUuid(string $userUuid): array|false
    {
        $sql = "SELECT uuid, username, email, firstname, lastname, phone, created_at, updated_at
                FROM " . $this->prefix() . "user WHERE uuid = :uuid AND deleted_at IS NULL LIMIT 1";
        $statement = $this->pdo()->prepare($sql);
        $statement->execute(['uuid' => $userUuid]);
        $user = $statement->fetch(\PDO::FETCH_ASSOC);

        if (!$user) {
            return false;
        }

        return $user;
    }

    /**
     * Login/register icin yeni token cifti kaydet. Plaintext token'lar
     * AuthService::hashToken ile SHA-256 hash'lenir; previous hash temizlenir.
     */
    /**
     * Kullanıcının oturum satırını DEĞİŞTİRİR: varsa siler, yenisini yazar.
     *
     * ─────────────────────────────────────────────────────────────────────
     * ESKİ HÂLİ ("addOrUpdateRefreshToken") ÜÇ SORUN TAŞIYORDU:
     *
     *  1. OKU-SONRA-YAZ YARIŞI. `getUserToken()` ile satır olup olmadığına
     *     bakıp UPDATE/INSERT seçiyordu. İki eşzamanlı login ikisi de "satır
     *     yok" görüp INSERT ediyordu. `user_uuid` UNIQUE OLMADIĞI için bu
     *     hata bile vermiyor, kullanıcıya SESSİZCE İKİ oturum satırı
     *     bırakıyordu — oysa tasarım kullanıcı başına tek satır varsayıyor
     *     (`getUserToken` LIMIT 1 okur, yani ikinci satır görünmez olurdu).
     *
     *  2. YUTULAN HATA. `catch (PDOException) { return false; }` her arızayı
     *     `false`'a çeviriyordu ve `AuthController::login()` dönüşü HİÇ
     *     kontrol etmiyordu → kullanıcı 200 + kaydedilmemiş token alıyordu.
     *     (`jti` eklenmeden önce aynı saniyedeki iki login aynı
     *     `access_token_hash`'i üretip UNIQUE ihlaline de düşüyordu.)
     *
     *  3. KATMAN İHLALİ. Model, `AuthService::hashToken()`'ı STATİK çağırarak
     *     servis katmanına uzanıyordu. Hash'ler artık parametre olarak gelir.
     *
     * Transaction'ı ÇAĞIRAN açar (`AuthService`, `Database::transaction()`
     * ile) — sözleşme gereği transaction sahipliği servistedir.
     * ─────────────────────────────────────────────────────────────────────
     */
    public function replaceToken(string $userUuid, string $accessHash, string $refreshHash): void
    {
        $delete = $this->pdo()->prepare(
            "DELETE FROM " . $this->prefix() . "users_token WHERE user_uuid = :user_uuid"
        );
        $delete->execute(['user_uuid' => $userUuid]);

        $insert = $this->pdo()->prepare(
            "INSERT INTO " . $this->prefix() . "users_token
             (uuid, user_uuid, refresh_token_hash, access_token_hash)
             VALUES (:uuid, :user_uuid, :refresh_token_hash, :access_token_hash)"
        );

        $insert->execute([
            // uuid uygulama tarafında üretilir (DB varsayılanına bağımlı değil).
            'uuid'               => Uuid::generate(),
            'user_uuid'          => $userUuid,
            'refresh_token_hash' => $refreshHash,
            'access_token_hash'  => $accessHash,
        ]);
    }

    public function getUserToken(string $userUuid): array|false
    {
        $sql = "SELECT uuid, user_uuid, access_token_hash, refresh_token_hash, previous_refresh_token_hash, created_at, updated_at
                FROM " . $this->prefix() . "users_token WHERE user_uuid = :user_uuid LIMIT 1";
        $statement = $this->pdo()->prepare($sql);
        $statement->execute(['user_uuid' => $userUuid]);
        return $statement->fetch(\PDO::FETCH_ASSOC) ?: false;
    }

    /**
     * Kullanicinin tum aktif session'larini sil (logout-all / reuse-detected senaryolari).
     */
    public function removeRefreshToken(string $userUuid): bool
    {
        $sql = "DELETE FROM " . $this->prefix() . "users_token WHERE user_uuid = :user_uuid";
        $statement = $this->pdo()->prepare($sql);
        try {
            $statement->execute(['user_uuid' => $userUuid]);
            return $statement->rowCount() > 0;
        } catch (PDOException) {
            return false;
        }
    }

    // NOT: `validateToken()` SİLİNDİ.
    //
    // `AuthService::validate()`'in birebir kopyasıydı: aynı sırla aynı JWT
    // çözümlemesini yapıyordu. Yani token doğrulama mantığının İKİ sahibi
    // vardı ve biri değişirse diğeri sessizce ayrışırdı. Kod tabanında hiçbir
    // çağıranı yoktu; `AuthConfig` bağımlılığı da yalnızca bu metot içindi.
    //
    // Token çözümlemesi tek yerde: `AuthService::validate()`.

    /**
     * Refresh token rotation: atomik UPDATE ile eski refresh hash'i previous'a
     * tasinir, yeni access+refresh hash'leri kaydedilir. WHERE clause race-safe.
     * Etkilenen satir sayisi 0 ise eslesme yok demek.
     */
    public function rotateRefreshToken(
        string $userUuid,
        string $oldRefreshTokenHash,
        string $newAccessTokenHash,
        string $newRefreshTokenHash
    ): bool {
        // ── SET SIRASI KRİTİK — GÜVENLİK DÜZELTMESİ ─────────────────────
        //
        // `previous_refresh_token_hash` ÖNCE atanır, `refresh_token_hash`
        // SONRA. MySQL `SET` maddesini SOLDAN SAĞA değerlendirir ve sonraki
        // ifadeler önceki ATAMALARI görür (ölçüldü).
        //
        // Eski sırada `refresh_token_hash` önce güncelleniyor, ardından
        // `previous_refresh_token_hash = refresh_token_hash` ZATEN YENİ olan
        // değeri kopyalıyordu. Sonuç: previous == current, yani
        // `isRefreshTokenReused()` eski hash'le HİÇBİR ZAMAN eşleşmiyordu ve
        // OAuth 2.0 BCP §4.13.2 "reuse tespit edilirse tüm oturumları iptal
        // et" kontrolü SESSİZCE HİÇ ÇALIŞMIYORDU. Çalınmış bir refresh
        // token'ın tekrar kullanımı yalnızca 401 alıyor, meşru kullanıcının
        // oturumu iptal EDİLMİYORDU — yani saldırgan denemeye devam
        // edebiliyordu.
        //
        // Not: bu davranış MySQL'e özgüdür. PostgreSQL `SET` ifadelerini
        // satırın ESKİ hâline göre değerlendirir, dolayısıyla orada eski sıra
        // da doğru çalışırdı — hatanın sürücüye bağlı olarak gizlenmesinin
        // sebebi budur.
        $sql = "UPDATE " . $this->prefix() . "users_token
                SET previous_refresh_token_hash = refresh_token_hash,
                    refresh_token_hash          = :new_refresh_hash,
                    access_token_hash           = :new_access_hash,
                    updated_at                  = NOW()
                WHERE user_uuid          = :user_uuid
                  AND refresh_token_hash = :old_refresh_hash";
        $statement = $this->pdo()->prepare($sql);
        try {
            $statement->execute([
                'user_uuid'        => $userUuid,
                'old_refresh_hash' => $oldRefreshTokenHash,
                'new_access_hash'  => $newAccessTokenHash,
                'new_refresh_hash' => $newRefreshTokenHash,
            ]);
            return $statement->rowCount() > 0;
        } catch (PDOException) {
            return false;
        }
    }

    /**
     * Verilen refresh token hash'i kullanicinin previous_refresh_token_hash'ine
     * esitse reuse tespit edilmis demektir (saldirgan ya da network retry).
     * OAuth 2.0 BCP: tum session'i invalidate et.
     */
    public function isRefreshTokenReused(string $userUuid, string $refreshTokenHash): bool
    {
        $sql = "SELECT 1 FROM " . $this->prefix() . "users_token
                WHERE user_uuid                   = :user_uuid
                  AND previous_refresh_token_hash = :hash
                LIMIT 1";
        $statement = $this->pdo()->prepare($sql);
        try {
            $statement->execute([
                'user_uuid' => $userUuid,
                'hash'      => $refreshTokenHash,
            ]);
            return (bool) $statement->fetchColumn();
        } catch (PDOException) {
            return false;
        }
    }

    public function validAccessToken(string $userUuid, string $accessTokenHash): bool
    {
        $sql = "SELECT 1 FROM " . $this->prefix() . "users_token
                WHERE user_uuid         = :user_uuid
                  AND access_token_hash = :hash
                LIMIT 1";
        $statement = $this->pdo()->prepare($sql);
        try {
            $statement->execute([
                'user_uuid' => $userUuid,
                'hash'      => $accessTokenHash,
            ]);
            return (bool) $statement->fetchColumn();
        } catch (PDOException) {
            return false;
        }
    }
}
