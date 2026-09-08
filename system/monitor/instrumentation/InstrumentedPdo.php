<?php

declare(strict_types=1);

namespace System\Monitor\Instrumentation;

use PDO;
use PDOStatement;
use System\Monitor\Contracts\RecorderInterface;

/**
 * `query()` ve `exec()` çağrılarını ölçen `PDO` alt sınıfı.
 *
 * `InstrumentedStatement` yalnızca `prepare()` + `execute()` yolunu kapsar;
 * `PDO::query()` statement'ı hazırlayıp ANINDA çalıştırır (dolayısıyla
 * `execute()` hiç çağrılmaz) ve `PDO::exec()` hiç statement üretmez. Bu iki
 * metot sarılmazsa ölçüm sessizce eksik kalır — örneğin
 * `api/models/UserModel.php:16`'daki doğrudan `query()` çağrısı görünmezdi.
 *
 * Yalnızca bu iki metot override edilir; PDO'nun geri kalanı miras alınır.
 * (Bir decorator yazmak 30'dan fazla metodu elle delege etmek anlamına
 * gelirdi; alt sınıflama gereken kadarını yapar.)
 *
 * Constructor'lar LSP'den muaf olduğu için PDO'nun imzasına fazladan bir
 * parametre eklemek güvenlidir.
 */
final class InstrumentedPdo extends PDO
{
    /**
     * @param array<int, mixed>|null $options
     */
    public function __construct(
        string $dsn,
        ?string $username,
        ?string $password,
        ?array $options,
        private readonly RecorderInterface $recorder,
    ) {
        parent::__construct($dsn, $username, $password, $options);
    }

    #[\Override]
    public function query(
        string $query,
        ?int $fetchMode = null,
        mixed ...$fetchModeArgs
    ): PDOStatement|false {
        $start = hrtime(true);

        try {
            // $fetchMode null iken parent'a açıkça null geçmek TypeError üretir;
            // argüman sayısına göre çağrılmalı.
            return $fetchMode === null
                ? parent::query($query)
                : parent::query($query, $fetchMode, ...$fetchModeArgs);
        } finally {
            $this->record($query, $start);
        }
    }

    #[\Override]
    public function exec(string $statement): int|false
    {
        $start = hrtime(true);

        try {
            return parent::exec($statement);
        } finally {
            $this->record($statement, $start);
        }
    }

    private function record(string $sql, float|int $start): void
    {
        $this->recorder->recordQuery(
            sql: $sql,
            // query()/exec() bağlı parametre almaz — SQL'in kendisi tam metindir.
            bindings: [],
            durationMs: (hrtime(true) - $start) / 1_000_000,
        );
    }
}
