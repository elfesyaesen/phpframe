<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';

date_default_timezone_set(
    (string) (System\Engine\Env::get('APP_TIMEZONE') ?: 'UTC')
);
