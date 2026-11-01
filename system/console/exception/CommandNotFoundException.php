<?php

declare(strict_types=1);

namespace System\Console\Exception;

class CommandNotFoundException extends ConsoleException
{
    /**
     * @param array<string> $alternatives
     */
    public function __construct(
        string $message,
        public readonly array $alternatives = [],
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * @return array<string>
     */
    public function getAlternatives(): array
    {
        return $this->alternatives;
    }
}
