<?php

declare(strict_types=1);

namespace System\Engine;

/**
 * Uygulama modüllerini dosya sisteminden keşfeder.
 *
 * Önceden modül listesi `.env`'deki `APP_MODULES` ile elle bildiriliyordu.
 * Bu gereksiz yere sınırlayıcıydı: yeni bir modül dizini açmak yetmiyor,
 * ayrıca config'e eklemek gerekiyordu; unutulduğunda route'lar sessizce
 * taranmıyor ve production'da "derlenmemiş servis" uyarıları çıkıyordu.
 * Artık modül olmak bir yapı gerçeğidir — uygun bir dizin varsa modüldür.
 *
 * Bir üst-seviye dizin şu koşulları sağlıyorsa modüldür:
 *  1. Adı `[a-z][a-z0-9_]*` desenine uyar. Bu, `BaseController`'ın view path
 *     deseniyle aynıdır ve autoloader'ın namespace→küçük-harf-dizin eşlemesiyle
 *     tutarlıdır (Linux'un case-sensitive dosya sisteminde şart).
 *  2. Framework'ün kendi dizinlerinden biri değildir.
 *  3. İçinde tanıdık bir modül alt dizini bulunur (`controllers`, `models`, ...).
 *     Bu koşul, `logs` veya `image` gibi rastgele bir dizinin modül sanılmasını
 *     engeller.
 */
final class ModuleRegistry
{
    /**
     * Framework'e ait, asla modül olmayan üst-seviye dizinler.
     */
    private const RESERVED = [
        'system', 'config', 'public', 'migration', 'migrations',
        'logs', 'lang', 'image', 'images', 'storage', 'vendor',
        'docs', 'tests', 'test', 'bin', 'node_modules',
    ];

    /**
     * Bir dizini "modül" yapan alt dizinler. En az biri bulunmalı.
     */
    private const MARKERS = [
        'controllers', 'models', 'services', 'middleware', 'security', 'views',
    ];

    private const NAME_PATTERN = '/^[a-z][a-z0-9_]*$/';

    /**
     * Keşif sonucu. Dizin taraması istek başına bir kez yapılır; hem
     * RouterFactory hem ContainerCompiler aynı sonucu kullanır.
     *
     * @var array<string, list<string>>
     */
    private static array $cache = [];

    /**
     * @return list<string> Modül dizin adları (küçük harf), alfabetik.
     */
    public static function discover(?string $appRoot = null): array
    {
        $appRoot ??= defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2);

        if (isset(self::$cache[$appRoot])) {
            return self::$cache[$appRoot];
        }

        $entries = @scandir($appRoot);

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

    /**
     * Keşif önbelleğini temizler. Testler ve uzun ömürlü CLI süreçleri için.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    private static function isModule(string $appRoot, string $entry): bool
    {
        if ($entry === '.' || $entry === '..') {
            return false;
        }

        // Nokta ile başlayanlar (.git, .claude) ve desene uymayan adlar dışarıda.
        if (!preg_match(self::NAME_PATTERN, $entry)) {
            return false;
        }

        if (in_array($entry, self::RESERVED, true)) {
            return false;
        }

        $path = $appRoot . DIRECTORY_SEPARATOR . $entry;

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
