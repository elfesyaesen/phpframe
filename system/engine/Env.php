<?php

declare(strict_types=1);

namespace System\Engine;

class Env
{
    private static array $variables = [];

    /**
     * @var array<string, string>
     */
    private static array $raw = [];

    private static bool $loaded = false;

    /**
     * @var list<string>
     */
    private static array $shadowed = [];

    private const KEY_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    private const RESERVED_KEY_PATTERN =
        '/^(HTTP_|REQUEST_|REMOTE_|SERVER_|SCRIPT_|PHP_|REDIRECT_)'
        . '|^(HTTPS|DOCUMENT_ROOT|PATH_INFO|PATH_TRANSLATED|QUERY_STRING|CONTENT_TYPE|CONTENT_LENGTH|GATEWAY_INTERFACE|AUTH_TYPE|argv|argc'
        . '|PHPFRAME_DOTENV_KEYS)$/';

    private const LOADED_MARKER = 'PHPFRAME_DOTENV_KEYS';

    /**
     * @throws \RuntimeException
     */
    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }

        $envFile = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . '.env';

        if (!is_file($envFile)) {
            throw new \RuntimeException(".env dosyası bulunamadı: {$envFile}");
        }

        $content = @file_get_contents($envFile);

        if ($content === false) {
            throw new \RuntimeException(".env dosyası okunamadı (izinleri kontrol edin): {$envFile}");
        }

        $entries = self::parse($content, $envFile);

        $marker    = getenv(self::LOADED_MARKER);
        $inherited = is_string($marker) && $marker !== '' ? array_flip(explode(',', $marker)) : [];

        foreach (array_diff_key($inherited, $entries) as $key => $_) {
            putenv((string) $key);
        }

        foreach ($entries as $key => $entry) {
            if (getenv($key) !== false && !isset($inherited[$key])) {
                self::$shadowed[] = $key;
                continue;
            }

            $raw   = $entry['value'];
            $value = $entry['quoted'] ? $raw : self::coerce($raw);

            self::$variables[$key] = $value;
            self::$raw[$key] = $raw;
            $_ENV[$key] = $value;

            putenv("{$key}={$raw}");
        }

        putenv(self::LOADED_MARKER . '=' . implode(',', array_keys(self::$raw)));

        self::$loaded = true;
    }

    /**
     * @param string $source
     * @return array<string, array{value: string, quoted: bool, line: int}>
     * @throws \RuntimeException
     */
    public static function parse(string $content, string $source = '.env'): array
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        $entries = [];

        foreach (preg_split('/\r\n|\n|\r/', $content) ?: [] as $index => $line) {
            $lineNo  = $index + 1;
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            $fail = static fn (string $reason): \RuntimeException
                => new \RuntimeException("{$source}:{$lineNo}: {$reason}");

            if (str_starts_with($trimmed, 'export ')) {
                $trimmed = ltrim(substr($trimmed, 7));
            }

            if (!str_contains($trimmed, '=')) {
                throw $fail("'=' yok — satır KEY=değer biçiminde olmalı");
            }

            [$key, $value] = explode('=', $trimmed, 2);
            $key = rtrim($key);

            if (preg_match(self::KEY_PATTERN, $key) !== 1) {
                throw $fail('geçersiz anahtar adı (harf veya _ ile başlamalı; harf, rakam, _ içermeli)');
            }

            if (preg_match(self::RESERVED_KEY_PATTERN, $key) === 1) {
                throw $fail("{$key} sunucu/istek değişkeni — .env'de tanımlanamaz");
            }

            if (isset($entries[$key])) {
                throw $fail("{$key} ikinci kez tanımlanmış (ilki: satır {$entries[$key]['line']})");
            }

            [$parsed, $quoted] = self::parseValue(ltrim($value), $key, $fail);

            if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $parsed) === 1) {
                throw $fail("{$key} değeri kontrol karakteri içeriyor");
            }

            $entries[$key] = ['value' => $parsed, 'quoted' => $quoted, 'line' => $lineNo];
        }

        return $entries;
    }

    /**
     * @param \Closure(string): \RuntimeException $fail
     * @return array{0: string, 1: bool}
     */
    private static function parseValue(string $value, string $key, \Closure $fail): array
    {
        $quote = $value[0] ?? '';

        if ($quote !== '"' && $quote !== "'") {
            return [rtrim((string) preg_replace('/\s+#.*$/', '', $value)), false];
        }

        $close = strpos($value, $quote, 1);

        if ($close === false) {
            throw $fail("{$key} değerinin tırnağı kapanmamış");
        }

        $rest = trim(substr($value, $close + 1));

        if ($rest !== '' && !str_starts_with($rest, '#')) {
            throw $fail("{$key} değerinde kapanış tırnağından sonra metin var");
        }

        return [substr($value, 1, $close - 1), true];
    }

    /**
     * @return list<string>
     */
    public static function shadowed(): array
    {
        return self::$shadowed;
    }

    private static function coerce(string $value): mixed
    {
        if ($value === '') {
            return '';
        }

        $lowerValue = strtolower($value);
        if ($lowerValue === 'true' || $lowerValue === '(true)') {
            return true;
        }
        if ($lowerValue === 'false' || $lowerValue === '(false)') {
            return false;
        }

        if ($lowerValue === 'null' || $lowerValue === '(null)') {
            return null;
        }

        if (is_numeric($value)) {
            return strpos($value, '.') !== false ? (float) $value : (int) $value;
        }

        return $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$variables[$key] ?? $_ENV[$key] ?? $_SERVER[$key] ?? null;
        if ($value !== null) {
            return $value;
        }

        $env = getenv($key);
        return $env === false ? $default : $env;
    }

    public static function raw(string $key, ?string $default = null): ?string
    {
        if (isset(self::$raw[$key])) {
            return self::$raw[$key];
        }

        $env = getenv($key);

        return $env === false ? $default : $env;
    }

    public static function has(string $key): bool
    {
        return isset(self::$variables[$key]) || isset($_ENV[$key]) || isset($_SERVER[$key]) || getenv($key) !== false;
    }

    public static function all(): array
    {
        return self::$variables;
    }

    public static function require(string $key): mixed
    {
        $value = self::get($key);

        if ($value === null) {
            throw new \RuntimeException("Zorunlu environment değişkeni tanımlanmamış: {$key}");
        }

        return $value;
    }
}
