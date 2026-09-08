<?php

declare(strict_types=1);

namespace System\Config;

use System\Engine\Env;

/**
 * Env okumanın TEK enjekte edilebilir noktası.
 *
 * `Env` static kalır ve bu bilinçlidir: doğası gereği process-global'dir
 * (`putenv`, `$_ENV`, `$_SERVER`) ve herhangi bir obje var olmadan, hatta
 * autoloader'dan önce çalışmak zorundadır.
 *
 * Kazanç, `Env`'i enjekte edilebilir yapmakta değil, ONA DOKUNAN YER SAYISINI
 * BİRE İNDİRMEKTE: bu göçten sonra tüm kod tabanında env'i okuyan tek yer
 * ConfigFactory olur (eskiden ~60 `define()` + dağınık `Env::get()` çağrısı).
 * Geri kalan her şey typed config objesi alır.
 *
 * Tip dönüşümleri burada toplanır ki her config sınıfı `(int)` cast'i
 * tekrarlamak zorunda kalmasın ve boş string ile "verilmemiş" ayrımı tek
 * yerde tanımlı olsun.
 */
final readonly class EnvReader
{
    public function get(string $key, mixed $default = null): mixed
    {
        return Env::get($key, $default);
    }

    public function has(string $key): bool
    {
        return Env::has($key);
    }

    /**
     * String değer.
     *
     * HAM biçim tercih edilir: `Env::get()` sayısal görünen değerleri int'e
     * çevirir ve `DB_PASS=0123456` gibi bir sır sessizce `123456` olur —
     * baştaki sıfır kaybolduğu için kimlik doğrulama başarısız olur, ama
     * uygulama çalışmaya devam eder. Ham biçim bu veri kaybını önler.
     */
    public function string(string $key, string $default = ''): string
    {
        $raw = Env::raw($key);

        if ($raw !== null) {
            return $raw;
        }

        $value = Env::get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = Env::get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $value = Env::get($key, $default);

        return is_numeric($value) ? (float) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = Env::get($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }

    /**
     * Virgülle ayrılmış listeyi diziye çevirir; boş parçalar atılır.
     *
     * @return list<string>
     */
    public function list(string $key, string $default = ''): array
    {
        $raw = $this->string($key, $default);

        if (trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn(string $item): bool => $item !== ''
        ));
    }

    /**
     * Env'de tanımlı anahtarların adları — derleme hash'ine girer (§31):
     * bir env anahtarı eklenip config davranışı değişirse derleme bayatlar.
     *
     * DEĞERLER DEĞİL yalnızca ANAHTARLAR alınır; sırlar hash payload'ına
     * girmez.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = array_keys(Env::all());
        sort($keys);

        return $keys;
    }
}
