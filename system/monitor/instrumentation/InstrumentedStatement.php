<?php

declare(strict_types=1);

namespace System\Monitor\Instrumentation;

use PDOStatement;
use System\Monitor\Contracts\RecorderInterface;

/**
 * Süre ölçen `PDOStatement`.
 *
 * PDO'nun kendi genişletme kancası (`PDO::ATTR_STATEMENT_CLASS`) ile devreye
 * girer; yalnızca `execute()` sarılır. Bu yolun seçilme nedeni:
 *
 *  - PDO'yu saran bir decorator yazmak, `Database::getConnection(): PDO` dönüş
 *    tipi yüzünden `PDO`'yu extend etmeyi ve 30'dan fazla metodu delege etmeyi
 *    gerektirirdi (sürüm-kırılgan, bakımı pahalı).
 *  - `BaseModel` seviyesinde ölçüm yapmak, `BaseModel`'i kullanmayan ham
 *    `$this->pdo->prepare()` çağrılarını kaçırırdı.
 *
 * Statement seviyesinde ölçüm, `prepare()` + `execute()` kullanan TÜM kodu —
 * `BaseModel::selectAll/selectOne/callFunction` dahil — hiçbir model
 * değişikliği olmadan kapsar.
 *
 * KISIT: `PDO::ATTR_STATEMENT_CLASS`, kalıcı bağlantılarla (`DB_PERSISTENT=true`)
 * birlikte desteklenmez. `Database::shouldInstrument()` bu durumu kontrol eder
 * ve enstrümantasyonu sessizce devre dışı bırakır; bağlantının kendisi asla
 * riske atılmaz. Durum `Database::isInstrumented()` ile görülebilir.
 *
 * NOT: Constructor `protected` OLMAK ZORUNDA — PDO, public constructor'lı bir
 * statement sınıfını kabul etmez.
 */
final class InstrumentedStatement extends PDOStatement
{
    protected function __construct(
        private readonly RecorderInterface $recorder,
    ) {}

    /**
     * @param array<int|string, mixed>|null $params
     */
    #[\Override]
    public function execute(?array $params = null): bool
    {
        $start = hrtime(true);

        try {
            return parent::execute($params);
        } finally {
            // `finally`: sorgu exception fırlatsa da (SQL hatası, kısıt ihlali)
            // ölçüm kaydedilir — yavaş VE patlayan sorgular en çok aranan
            // olanlardır. Kayıt sözleşme gereği exception fırlatmaz.
            $this->recorder->recordQuery(
                sql: $this->queryString,
                // bindValue/bindParam ile bağlanan parametreler burada
                // görünmez; PDO onları dışa vermez. execute($params) yolu
                // (BaseModel'in kullandığı yol) tam kapsanır.
                bindings: $params ?? [],
                durationMs: (hrtime(true) - $start) / 1_000_000,
            );
        }
    }
}
