<?php

declare(strict_types=1);

namespace System\Engine;

final class ModuleRegistry
{
    public const DIR = 'app';
    public const ROOT_NAMESPACE = 'App';

    private const MARKERS = [
        'controllers', 'models', 'services', 'middleware', 'security', 'views',
    ];

    private const NAME_PATTERN = '/^[a-z][a-z0-9_]*$/';

    /**
     * @var array<string, list<string>>
     */
    private static array $cache = [];

    /**
     * @return list<string>
     */
    public static function discover(?string $appRoot = null): array
    {
        $appRoot = self::root($appRoot);

        if (isset(self::$cache[$appRoot])) {
            return self::$cache[$appRoot];
        }

        $entries = @scandir($appRoot . DIRECTORY_SEPARATOR . self::DIR);

        if ($entries === false) {
            return self::$cache[$appRoot] = [];
        }

        $modules = [];

        foreach ($entries as $entry) {
            if (!self::isModule($appRoot, $entry)) {
                continue;
            }

            $modules[] = $entry;
        }

        sort($modules);

        return self::$cache[$appRoot] = $modules;
    }

    public static function path(string $module, string $sub = '', ?string $appRoot = null): string
    {
        $path = self::root($appRoot) . DIRECTORY_SEPARATOR . self::DIR . DIRECTORY_SEPARATOR . $module;

        return $sub === '' ? $path : $path . DIRECTORY_SEPARATOR . $sub;
    }

    public static function namespace(string $module, string $sub = ''): string
    {
        $namespace = self::ROOT_NAMESPACE . '\\' . ucfirst($module);

        return $sub === '' ? $namespace : $namespace . '\\' . $sub;
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }

    private static function root(?string $appRoot): string
    {
        return rtrim($appRoot ?? (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2)), '/\\');
    }

    private static function isModule(string $appRoot, string $entry): bool
    {
        if ($entry === '.' || $entry === '..') {
            return false;
        }

        if (!preg_match(self::NAME_PATTERN, $entry)) {
            return false;
        }

        $path = self::path($entry, '', $appRoot);

        if (!is_dir($path)) {
            return false;
        }

        foreach (self::MARKERS as $marker) {
            if (is_dir($path . DIRECTORY_SEPARATOR . $marker)) {
                return true;
            }
        }

        return false;
    }
}
