<?php

declare(strict_types=1);

namespace System\Database;

use PDO;
use PDOException;
use System\Config\DatabaseConfig;
use System\Config\MonitorConfig;
use System\Exceptions\InternalServerException;
use System\Monitor\Instrumentation\InstrumentedPdo;
use System\Monitor\Instrumentation\InstrumentedStatement;
use System\Monitor\RecorderLocator;
use Throwable;

class Database
{
    /**
     * Bağlantı LAZY kurulur.
     *
     * Eskiden constructor'da açılıyordu. Sonucu: `Database`'i ÇÖZMEK bir
     * TCP/TLS el sıkışması demekti — ve `Schema`, `MigrationRunner`, her
     * model, `Gate` (RoleModel + PermissionModel üzerinden) ve `app:health`
     * onu çözdüğü için, tamamen Redis'ten servis edilen bir istek bile
     * bağlantı bedeli ödüyordu. `frame list` gibi hiç sorgu yapmayan bir
     * komut bile MySQL'e bağlanıyordu.
     *
     * DI-plan §14 (lazy loading) tam olarak bunu yasaklar: yalnızca
     * bağımlılık zincirinde GERÇEKTEN kullanılan nesneler kurulmalı.
     * Bağlantı artık ilk sorguda açılır.
     */
    private ?PDO $pdo = null;

    public function __construct(
        private readonly DatabaseConfig $config,
        /**
         * Recorder LOCATOR'ı, Recorder'ın kendisi DEĞİL.
         *
         * Database singleton (worker başına tek PDO), Recorder ise scoped
         * (per-request trace). Recorder'ı doğrudan enjekte etmek DI-plan
         * §19'un yasakladığı Singleton → Scoped kenarıdır ve burada bir lint
         * uyarısından çok daha kötüsüdür: `ATTR_STATEMENT_CLASS` recorder'ı
         * bağlantıya GÖMER, yani aynı worker'daki 2. istek sorgularını
         * 1. isteğin trace'ine yazar. Locator her çağrıda aktif scope'un
         * recorder'ını çözerek bunu önler.
         */
        private readonly RecorderLocator $recorder,
        private readonly MonitorConfig $monitor,
    ) {
    }

    /**
     * PDO bağlantısı. İlk çağrıda kurulur, sonrasında yeniden kullanılır.
     */
    public function getConnection(): PDO
    {
        return $this->pdo ??= $this->connect();
    }

    /**
     * `$fn`'i tek bir transaction içinde çalıştırır.
     *
     * ─────────────────────────────────────────────────────────────────────
     * NEDEN BURADA, BaseModel'de DEĞİL: transaction'ın sahibi SERVİS
     * katmanıdır (bkz. docs/api-layer.md), çünkü tek bir kullanım senaryosu
     * birden fazla model'e yayılabilir — "kullanıcı oluştur + refresh token
     * yaz" `UserModel` ve `AuthModel`'e dokunur. `BaseModel::pdo()` protected
     * olduğu için servisler oradan transaction açamaz; `BaseModel`'e bir
     * yardımcı koymak ise kontrolü tekrar Model'e verir ve o senaryo hiç
     * ifade edilemez.
     *
     * İÇ İÇE ÇAĞRI KASTEN HATA VERİR, savepoint AÇILMAZ. Gerekçe: PDO iç içe
     * `beginTransaction()`'ı desteklemez ve savepoint emülasyonu sessiz kısmi
     * rollback'e yol açabilen bir yeniden-giriş yüzeyi ekler. Test altyapısı
     * yokken gürültülü bir hata, sessiz veri bozulmasından iyidir.
     *
     * `$fn` fırlatırsa rollback yapılır ve istisna AYNEN yeniden fırlatılır —
     * yutulmaz, sarılmaz; çağıran kendi domain istisnasını görmelidir.
     * ─────────────────────────────────────────────────────────────────────
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        $pdo = $this->getConnection();

        if ($pdo->inTransaction()) {
            throw new InternalServerException(
                message: 'İç içe transaction desteklenmiyor',
                context: ['hint' => 'Dıştaki transaction zaten açık; iç çağrıyı transaction dışına alın.']
            );
        }

        $pdo->beginTransaction();

        try {
            $result = $fn();
        } catch (Throwable $e) {
            // `inTransaction()` kontrolü ZORUNLU: MySQL'de DDL örtük commit
            // tetikler, o durumda rollBack() "no active transaction" fırlatır
            // ve ASIL istisnayı maskelerdi.
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        $pdo->commit();

        return $result;
    }

    /** Şu an açık bir transaction var mı? */
    public function inTransaction(): bool
    {
        // Bağlantı henüz kurulmadıysa transaction da olamaz — burada
        // getConnection() ÇAĞRILMAZ, aksi halde salt bir durum sorgusu
        // TCP/TLS el sıkışması tetiklerdi (bkz. sınıf başındaki lazy notu).
        return $this->pdo !== null && $this->pdo->inTransaction();
    }

    public function getDriver(): DatabaseDriver
    {
        return $this->config->driver;
    }

    public function getConfig(): DatabaseConfig
    {
        return $this->config;
    }

    /** Bağlantı henüz kuruldu mu? (teşhis / `app:health` için) */
    public function isConnected(): bool
    {
        return $this->pdo !== null;
    }

    /**
     * Sorgu enstrümantasyonu bu bağlantı için açılacak mı?
     *
     * SAF ve bağlantı kurmadan yanıtlanabilir olmak ZORUNDA: `app:health`
     * durumu raporlarken veritabanına bağlanmamalı.
     *
     * Eskiden `$this->recorder->isRecording()` de kontrol ediliyordu; artık
     * kontrol edilmiyor çünkü Recorder scoped ve bu karar bağlantı kurulurken
     * (yani belirsiz bir istek içinde) veriliyor olurdu. Karar artık process
     * boyunca STABİL: yalnızca config'e bakar.
     */
    public function isInstrumented(): bool
    {
        // `DB_PERSISTENT` kontrolü MonitorConfig::instrumentsQueries() içinde:
        // PDO, ATTR_STATEMENT_CLASS'ı kalıcı bağlantılarda desteklemez ve
        // denenirse bağlantı kurulumu başarısız olur. İzleme uğruna
        // veritabanı erişimini kaybetmek kabul edilemez.
        return $this->monitor->instrumentsQueries($this->config);
    }

    private function connect(): PDO
    {
        $dsn = $this->config->dsn();
        $options = $this->config->pdoOptions();
        $instrumented = $this->isInstrumented();

        if ($instrumented) {
            // prepare() + execute() yolunu ölçer — BaseModel'i kullanan tüm
            // modeller tek satırla kapsanır, model kodu değişmez.
            $options[PDO::ATTR_STATEMENT_CLASS] = [
                InstrumentedStatement::class,
                [$this->recorder],
            ];
        }

        try {
            // InstrumentedPdo yalnızca query()/exec() için gerekir (bunlar
            // statement'ın execute()'unu hiç çağırmaz). Enstrümantasyon
            // kapalıyken düz PDO kurulur: izleme kapalıysa hiçbir ek katman yok.
            return $instrumented
                ? new InstrumentedPdo(
                    $dsn,
                    $this->config->user,
                    $this->config->password,
                    $options,
                    $this->recorder
                )
                : new PDO($dsn, $this->config->user, $this->config->password, $options);
        } catch (PDOException $e) {
            throw new InternalServerException(
                message: 'Veritabanı bağlantı hatası',
                // Şifre ASLA bağlama girmez (bkz. toSafeContext).
                context: [...$this->config->toSafeContext(), 'error' => $e->getMessage()],
                previous: $e
            );
        }
    }
}
