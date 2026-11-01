<?php

declare(strict_types=1);

$public = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'public';
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$file = realpath($public . $path);

if ($file !== false
    && is_file($file)
    && str_starts_with($file, realpath($public) . DIRECTORY_SEPARATOR)
    && !str_ends_with($file, '.php')
) {
    return false;
}

require $public . DIRECTORY_SEPARATOR . 'index.php';
