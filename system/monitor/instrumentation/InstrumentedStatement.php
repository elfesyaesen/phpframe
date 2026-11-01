<?php

declare(strict_types=1);

namespace System\Monitor\Instrumentation;

use PDOStatement;
use System\Monitor\Contracts\RecorderInterface;

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
            $this->recorder->recordQuery(
                sql: $this->queryString,

                bindings: $params ?? [],
                durationMs: (hrtime(true) - $start) / 1_000_000,
            );
        }
    }
}
