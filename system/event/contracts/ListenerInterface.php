<?php

declare(strict_types=1);

namespace System\Event\Contracts;

/**
 * Event Listener Interface
 *
 * Sınıf tabanlı event listener'lar için interface.
 */
interface ListenerInterface
{
    /**
     * Event'i işle
     *
     * @param array $payload Event verileri
     * @return bool|void False döndürülürse sonraki listener'lar çalışmaz
     */
    public function handle(array $payload): bool|null;
}
