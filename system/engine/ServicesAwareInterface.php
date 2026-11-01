<?php

declare(strict_types=1);

namespace System\Engine;

interface ServicesAwareInterface
{
    public function setServices(ControllerServices $services): void;
}
