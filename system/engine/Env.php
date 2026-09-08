<?php

declare(strict_types=1);

namespace System\Engine;

class Env
{
    private static array $variables = [];

    /**
     * Tip dönüşümü UYGULANMAMIŞ değerler (yalnızca tırnak soyulmuş).
     *
     * NEDEN GEREKLİ: parseValue() sayısal görünen her değeri int/float'a
     * çevirir. Bu, sayı olması BEKLENEN ayarlar için doğru ama sırlar için
     * VERİ KAYBIDIR:
     *
     *   DB_PASS=0123456   →  (int) 123456   →  baştaki sıfır düşer
     *                                          → yanlış şifre, "erişim reddedildi"
     *
     * Aynı tuzak SECRET_KEY, MONITOR_TOKEN, REDIS_PASSWORD ve SMTP_PASSWORD
     * için de geçerlidir; hepsi tamamı rakamdan oluşabilir. Hata sessizdir:
     * uygulama çalışır, yalnızca kimlik doğrulama başarısız olur.
     *
     * Bu yüzden ham biçim ayrıca saklanır ve string okuyan taraf (EnvReader)
     * onu tercih eder.
     *
     * @var array<string, string>
     */
    private static array $raw = [];

    private static bool $loaded = false;

    /**
     * .env dosyasını yükler 
     */
    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }

        $envFile = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.env';

        if (!file_exists($envFile)) {
            throw new \RuntimeException(".env dosyası bulunamadı: {$envFile}");
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            // Yorum satırlarını atla
            if (str_starts_with(trim($line), '#')) {
                continue;
            }

            // = işareti içermiyorsa atla
            if (strpos($line, '=') === false) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);

            $key = trim($key);
            $value = trim($value);

            // Tırnak soyma ile tip dönüşümü AYRI adımlar: ham (tip dönüşümü
            // uygulanmamış) biçim sırlar için korunmak zorunda (bkz. $raw).
            $raw = self::unquote($value);
            $value = self::isQuoted($value) ? $raw : self::coerce($raw);

            // Değişkeni kaydet
            self::$variables[$key] = $value;
            self::$raw[$key] = $raw;

            // $_ENV ve $_SERVER'a da ekle
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;

            // putenv ham biçimi alır: gerçek environment her zaman string'dir
            // ve tip dönüşümü uygulanmış bir değeri oraya yazmak veri kaybeder.
            putenv("{$key}={$raw}");
        }

        self::$loaded = true;
    }

    /**
     * Değeri parse eder (tırnak kaldırma, tip dönüşümü)
     */
    private static function parseValue(string $value): mixed
    {
        return self::isQuoted($value)
            ? self::unquote($value)
            : self::coerce($value);
    }

    /**
     * Değer tırnak içinde mi? Tırnaklı bir değer tip dönüşümüne GİRMEZ:
     * kullanıcı tırnak koyarak "bu bir string" demiş sayılır
     * (`DB_PASS="0123"` → '0123', int 123 değil).
     */
    private static function isQuoted(string $value): bool
    {
        return strlen($value) >= 2
            && ((str_starts_with($value, '"') && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'")));
    }

    private static function unquote(string $value): string
    {
        return self::isQuoted($value) ? substr($value, 1, -1) : $value;
    }

    /**
     * Tip dönüşümü uygular (bool/null/int/float).
     */
    private static function coerce(string $value): mixed
    {
        if ($value === '') {
            return '';
        }

        // Boolean değerler
        $lowerValue = strtolower($value);
        if ($lowerValue === 'true' || $lowerValue === '(true)') {
            return true;
        }
        if ($lowerValue === 'false' || $lowerValue === '(false)') {
            return false;
        }

        // Null değer
        if ($lowerValue === 'null' || $lowerValue === '(null)') {
            return null;
        }

        // Sayısal değerler
        if (is_numeric($value)) {
            return strpos($value, '.') !== false ? (float) $value : (int) $value;
        }

        return $value;
    }

    /**
     * Environment değişkenini döndürür
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        // DİKKAT: `?:` operatörü `??`'dan düşük önceliklidir. Tek satırda
        // `... ?? getenv($key) ?: $default` yazmak, çözülen değer FALSY olduğunda
        // (false, 0, '', '0') sessizce $default'a düşer — `DEBUG=false`, `WORKERS=0`
        // gibi geçerli config değerleri kaybolurdu. Bu yüzden açık adımlar kullanılır.
        $value = self::$variables[$key] ?? $_ENV[$key] ?? $_SERVER[$key] ?? null;
        if ($value !== null) {
            return $value;
        }

        $env = getenv($key);
        return $env === false ? $default : $env;
    }

    /**
     * Tip dönüşümü UYGULANMAMIŞ ham değeri döndürür.
     *
     * String olması gereken ayarlar — özellikle SIRLAR — bunu kullanmak
     * zorunda: `get()` sayısal görünen bir şifreyi int'e çevirir ve baştaki
     * sıfırlar kaybolur (bkz. $raw docblock'u).
     *
     * `.env` dosyasında yoksa gerçek environment'a düşer (orada değerler
     * her zaman string'dir, dolayısıyla veri kaybı olmaz).
     */
    public static function raw(string $key, ?string $default = null): ?string
    {
        if (isset(self::$raw[$key])) {
            return self::$raw[$key];
        }

        $env = getenv($key);

        return $env === false ? $default : $env;
    }

    /**
     * Environment değişkeninin varlığını kontrol eder
     */
    public static function has(string $key): bool
    {
        return isset(self::$variables[$key]) || isset($_ENV[$key]) || isset($_SERVER[$key]) || getenv($key) !== false;
    }

    /**
     * Tüm environment değişkenlerini döndürür
     */
    public static function all(): array
    {
        return self::$variables;
    }

    /**
     * Environment değişkenini zorunlu olarak alır
     */
    public static function require(string $key): mixed
    {
        $value = self::get($key);

        if ($value === null) {
            throw new \RuntimeException("Zorunlu environment değişkeni tanımlanmamış: {$key}");
        }

        return $value;
    }
}

// ─────────────────────────────────────────────────────────────────────────
// NOT: Global `env()` helper'ı KALDIRILDI.
//
// İki gerekçe:
//
// 1. Env okumasını yeniden dağıtmaya davetiyeydi. Typed config göçünün tüm
//    amacı, env'e dokunan yer sayısını BİRE indirmekti
//    (`System\Config\ConfigFactory`); global bir helper, herhangi bir
//    sınıfın bağımlılığını imzasında bildirmeden yapılandırma okumasına
//    geri dönmesini kolaylaştırıyordu.
//
// 2. `Env::get()` sayısal görünen değerleri tip dönüşümüne sokar; sırlar
//    için bu VERİ KAYBIDIR (bkz. $raw docblock'u). Helper bu tuzağı
//    en kolay erişilebilir API hâline getiriyordu.
//
// Yapılandırma okumak için: constructor'da typed config objesi al
// (`DatabaseConfig`, `MonitorConfig`, ...). Tek bir skaler gerekiyorsa
// `#[Config('anahtar')]` attribute'u (DI-plan §22).
// ─────────────────────────────────────────────────────────────────────────
