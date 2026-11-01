<?php

declare(strict_types=1);

if (PHP_VERSION_ID < 80500) {
    $message = sprintf(
        'PHPFrame PHP 8.5 veya üzerini gerektirir. Mevcut sürüm: %s',
        PHP_VERSION
    );

    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
    }

    exit($message . PHP_EOL);
}

$appRoot = dirname(__DIR__);

define('APP_ROOT', $appRoot);

require_once $appRoot . '/system/engine/Env.php';
System\Engine\Env::load($appRoot);

require_once $appRoot . '/vendor/autoload.php';

require_once $appRoot . '/system/engine/Autoloader.php';
new System\Engine\Autoloader();

unset($appRoot);
