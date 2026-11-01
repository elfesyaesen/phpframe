<?php

declare(strict_types=1);

namespace System\Monitor\Contracts;

use System\Monitor\RequestTrace;

interface SamplerInterface
{
    public function shouldStore(RequestTrace $trace): bool;
}
