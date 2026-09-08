<?php

declare(strict_types=1);

namespace System\Engine;

use PDO;
use PDOException;
use System\Cache\CacheInterface;
use System\Database\Database;

/**
 * Model katmanı tabanı — sözleşme: `docs/api-layer.md`.
 *
 * (Bu docblock eskiden `PHPFrame.md §5 / §6`'ya atıf yapıyordu; O DOSYA HİÇ
 * VAR OLMADI. Model ve servis katmanının kuralları artık gerçekten yazılı.)
 *
 * PDO erişimini cache-first okuma (remember) ve DB-compute (view/function)
 * çağrı yardımcılarıyla birleştirir. Somut model'ler bunu extend eder; read'ler
 * remember() ile sarılabilir, write'larda forget() ile cache geçersiz kılınır.
 *
 * Cache anahtar stratejisi: cacheKey('entity', id, ...) -> "entity:id:..."
 * (genel CACHE_PREFIX zaten CacheInterface tarafında eklenir).
 */
abstract class BaseModel
{
    public function __construct(
        private readonly Database $database,
        protected readonly CacheInterface $cache,
    ) {
    }

    /**
     * Tablo öneki (`DB_PREFIX` sabitinin yerine).
     *
     * Config `Database`'den alınır, ayrı bir constructor parametresi
     * EKLENMEZ: her somut model ve `MonitorModel::__construct`'ın
     * `parent::__construct($database, $cache)` çağrısı bozulmadan kalsın.
     * Bağlantı açılmadan erişilebilir — `getConfig()` yalnızca veri döndürür.
     */
    protected function prefix(): string
    {
        return $this->database->getConfig()->prefix;
    }

    // NOT: `table()` gibi genel bir yardımcı EKLENMEDİ. `MonitorModel` zaten
    // kendi `table()` metoduna sahip ve taban sınıfa aynı adı eklemek erişim
    // seviyesi çakışması (fatal) üretiyordu. Önek gerektiren yerler
    // `$this->prefix()` kullanır.

    /**
     * PDO bağlantısı — LAZY.
     *
     * Eskiden `$this->pdo` constructor'da doldurulan bir property'ydi, yani
     * HERHANGİ bir modeli çözmek bir veritabanı bağlantısı açıyordu. Bu, kod
     * tabanındaki en büyük DI-plan §14 (lazy loading) ihlaliydi:
     *
     *   • tamamen Redis'ten servis edilen bir istek de TCP/TLS el sıkışması
     *     ödüyordu — cache-first mimarisinin amacını kısmen boşa çıkarıyordu
     *   • `Gate` (RoleModel + PermissionModel alır) her kimlikli istekte
     *     bağlanıyordu, yetki cache'ten gelse bile
     *   • hiç sorgu yapmayan CLI komutları (`frame list`) MySQL'e bağlanıyordu
     *
     * `Database::getConnection()` bağlantıyı memoize eder; bu metot her
     * çağrıda yeni bağlantı açmaz.
     */
    protected function pdo(): PDO
    {
        return $this->database->getConnection();
    }

    // ── Cache-first yardımcıları ──

    /**
     * Değer cache'te varsa onu döner; yoksa $callback çalıştırılıp saklanır.
     */
    protected function remember(string $key, ?int $ttl, callable $callback): mixed
    {
        return $this->cache->remember($key, $ttl, $callback);
    }

    /**
     * Yazma sonrası ilgili cache anahtarını geçersiz kılar.
     */
    protected function forget(string $key): bool
    {
        return $this->cache->delete($key);
    }

    /**
     * ':' ile birleştirilmiş cache anahtarı üretir.
     */
    protected function cacheKey(string ...$parts): string
    {
        return implode(':', $parts);
    }

    // ── Sorgu yardımcıları ──

    /**
     * Bu `PDOException` bir UNIQUE kısıtı ihlali mi?
     *
     * Sürücüye göre değişen iki gösterim tek yerde birleştirilir:
     *   MySQL      → SQLSTATE 23000 + driver kodu 1062
     *   PostgreSQL → SQLSTATE 23505
     *
     * Bu kontrol `RoleModel`, `PermissionModel` ve `UserModel` içinde ÜÇ AYRI
     * kopya hâlinde duruyordu. Sürücü davranışı bilgisi persistence tabanına
     * ait; her modelde tekrar edilmesi, yeni bir sürücü eklendiğinde üç yerin
     * birden güncellenmesini gerektiriyordu — ve birini atlamak sessizce
     * "unique ihlali değil" sonucu üretirdi.
     */
    protected function isUniqueViolation(PDOException $e): bool
    {
        $sqlState   = (string) ($e->errorInfo[0] ?? '');
        $driverCode = $e->errorInfo[1] ?? null;

        return $sqlState === '23505' || ($sqlState === '23000' && $driverCode === 1062);
    }

    /**
     * Bu `PDOException` bir YABANCI ANAHTAR kısıtı ihlali mi?
     *
     *   MySQL      → SQLSTATE 23000 + 1452 (child eklenemiyor) / 1451 (parent silinemiyor)
     *   PostgreSQL → SQLSTATE 23503
     *
     * Ayrımın önemi: FK ihlali "istemci var olmayan bir kayda referans verdi"
     * demektir (istemci hatası), bağlantı kopması ya da şema uyuşmazlığı ise
     * sunucu arızasıdır. İkisini aynı `catch (PDOException) { return false; }`
     * içinde toplamak, gerçek arızaları sessizce "doğrulama hatası" gibi
     * gösterir ve loglardan siler.
     */
    protected function isForeignKeyViolation(PDOException $e): bool
    {
        $sqlState   = (string) ($e->errorInfo[0] ?? '');
        $driverCode = $e->errorInfo[1] ?? null;

        return $sqlState === '23503'
            || ($sqlState === '23000' && in_array($driverCode, [1451, 1452], true));
    }

    /**
     * Çok satırlı SELECT.
     *
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    protected function selectAll(string $sql, array $params = []): array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Tek satırlık SELECT; bulunamazsa null.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    protected function selectOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * DB-compute fonksiyonunu çağırır: SELECT * FROM fn(:a, :b, ...).
     * Parametre anahtarları sırasıyla fonksiyon argümanlarına bağlanır.
     *
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    protected function callFunction(string $function, array $params = []): array
    {
        $placeholders = implode(
            ', ',
            array_map(static fn(string $k): string => ':' . $k, array_keys($params))
        );

        return $this->selectAll(
            'SELECT * FROM ' . $function . '(' . $placeholders . ')',
            $params
        );
    }
}
