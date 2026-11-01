<?php

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';

$kernel = System\Runtime\Kernel::create(System\Runtime\Sapi::Http, APP_ROOT);

$container = $kernel->container();
