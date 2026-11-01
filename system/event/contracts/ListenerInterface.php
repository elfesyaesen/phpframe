<?php

declare(strict_types=1);

namespace System\Event\Contracts;

interface ListenerInterface
{
    /**
     * @param array $payload
     * @return bool|void
     */
    public function handle(array $payload): bool|null;
}
