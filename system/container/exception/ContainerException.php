<?php

declare(strict_types=1);

namespace System\Container\Exception;

use Psr\Container\ContainerExceptionInterface;
use RuntimeException;
use Throwable;

/**
 * Tüm container hatalarının tabanı (DI-plan §29).
 *
 * Her hata iki şey taşımak zorunda:
 *   1. Makine tarafından ayrıştırılabilir bir KOD (`[CIRCULAR_DEPENDENCY]` gibi) —
 *      loglarda grep'lenebilir, CLI tarafından gruplanabilir.
 *   2. Çözümleme ZİNCİRİ — "hangi servis hangi servisi isterken patladı".
 *      Zincirsiz bir DI hatası, 40 katmanlı bir grafikte işe yaramaz.
 *
 * Mesaj biçimi tekdüzedir (bkz. render()):
 *
 *   [CODE] Tek satır iddia.
 *
 *     A\Service
 *      → B\Service
 *      → C\Service  ✗ <işaret>
 *
 *     Çözüm: ...
 *
 * `[CODE]` token'ları İngilizce (grep'lenebilirlik), açıklama satırları Türkçe
 * (kod tabanının geri kalanıyla tutarlı).
 */
class ContainerException extends RuntimeException implements ContainerExceptionInterface
{
    /**
     * @param string       $errorCode Makine kodu, örn. 'CIRCULAR_DEPENDENCY'
     * @param list<string> $chain   Çözümleme zinciri (kök → hatalı node)
     * @param string|null  $service Hatanın ait olduğu servis id'si
     */
    public function __construct(
        string $message,
        public readonly string $errorCode = 'CONTAINER_ERROR',
        public readonly array $chain = [],
        public readonly ?string $service = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Zincire satır ekler, ardışık tekrarları yutar.
     *
     * Çözümleme zinciri hatanın oluştuğu id'yi çoğu zaman ZATEN içerir
     * ($resolving guard'ı onu koymuş olur). Kör bir `$chain[] = $id` "T → M → M"
     * gibi kafa karıştırıcı çıktı üretir; bu helper onu engeller.
     *
     * @param list<string> $chain
     * @return list<string>
     */
    protected static function chainWith(array $chain, string ...$extra): array
    {
        foreach ($extra as $line) {
            if ($line !== '' && ($chain === [] || $chain[count($chain) - 1] !== $line)) {
                $chain[] = $line;
            }
        }

        return $chain;
    }

    /**
     * Hata mesajını tekdüze biçimde kurar.
     *
     * @param list<string> $chain    Zincir satırları (zaten biçimlenmiş, örn. "A  (singleton)  file:12")
     * @param string|null  $marker   Son zincir satırına eklenecek işaret, örn. "döngü burada kapanıyor"
     * @param string|null  $fix      Çözüm önerisi (çok satırlı olabilir)
     */
    protected static function render(
        string $code,
        string $claim,
        array $chain = [],
        ?string $marker = null,
        ?string $fix = null,
    ): string {
        $out = "[{$code}] {$claim}";

        if ($chain !== []) {
            $out .= "\n";
            $last = count($chain) - 1;
            foreach ($chain as $i => $line) {
                $prefix = $i === 0 ? '  ' : '   → ';
                $suffix = ($i === $last && $marker !== null) ? '  ✗ ' . $marker : '';
                $out .= "\n" . $prefix . $line . $suffix;
            }
        }

        if ($fix !== null) {
            $out .= "\n\n  " . str_replace("\n", "\n  ", $fix);
        }

        return $out;
    }

    /**
     * Build-time'da verilen ama derlenmiş container'a ulaşmayan bir INSTANCE.
     * Neredeyse her zaman: bootstrap derlenmiş container'ı kurarken externals
     * dizisine o id'yi koymayı atlamış.
     */
    public static function missingExternal(string $id): self
    {
        return new self(
            self::render(
                'MISSING_EXTERNAL',
                'Derlenmiş container dışarıdan verilmesi gereken bir servisi bulamadı.',
                [$id . '  (instance)'],
                'construction sırasında verilmedi',
                "Bu servis `instance()` ile kaydedilmiş, yani canlı bir obje —\n"
                . "derlenmiş PHP dosyasına gömülemez, dışarıdan verilmek zorundadır.\n"
                . "Çözüm: Kernel derlenmiş container'ı kurarken externals dizisine ekle:\n"
                . "  new CompiledContainer_<hash>([{$id}::class => \$value]);"
            ),
            'MISSING_EXTERNAL',
            [$id],
            $id,
        );
    }

    /** Kilitli (production) container veya builder üzerinde mutasyon denemesi (DI-plan §24). */
    public static function locked(string $id, string $operation = 'bind'): self
    {
        return new self(
            self::render(
                'CONTAINER_LOCKED',
                'Kilitli container/builder üzerinde tanım değiştirilemez.',
                [$id],
                "{$operation}() çağrıldı",
                "Container build tamamlandıktan sonra kilitlenir (DI-plan §24):\n"
                . "  BUILD TIME → ContainerBuilder → CompiledContainer → LOCKED\n"
                . "Çözüm: tanımı bir ServiceProvider::register() içine taşı.\n"
                . "Runtime'da servis eklemek service locator anti-pattern'idir."
            ),
            'CONTAINER_LOCKED',
            [$id],
            $id,
        );
    }
}
