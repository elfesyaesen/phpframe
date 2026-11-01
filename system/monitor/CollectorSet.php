<?php

declare(strict_types=1);

namespace System\Monitor;

use System\Container\Attribute\Tagged;
use System\Monitor\Contracts\CollectorInterface;

final readonly class CollectorSet
{
    /**
     * @param list<CollectorInterface> $collectors
     */
    public function __construct(
        #[Tagged(self::TAG)] public array $collectors,
    ) {}

    public const TAG = 'monitor.collector';

    /**
     * @return list<CollectorInterface>
     */
    public function all(): array
    {
        return $this->collectors;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(
            static fn(CollectorInterface $collector): string => $collector->name(),
            $this->collectors,
        );
    }
}
