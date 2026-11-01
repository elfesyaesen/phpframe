<?php

declare(strict_types=1);

namespace System\Monitor\Contracts;

use System\Monitor\RequestTrace;

interface CollectorInterface
{
    public function name(): string;

    public function collect(RequestTrace $trace): void;
}
