<?php

declare(strict_types=1);

namespace System\Monitor\Instrumentation;

use PDO;
use PDOStatement;
use System\Monitor\Contracts\RecorderInterface;

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
            bindings: [],
            durationMs: (hrtime(true) - $start) / 1_000_000,
        );
    }
}
