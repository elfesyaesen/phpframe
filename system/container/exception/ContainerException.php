<?php

declare(strict_types=1);

namespace System\Container\Exception;

use Psr\Container\ContainerExceptionInterface;
use RuntimeException;
use Throwable;

class ContainerException extends RuntimeException implements ContainerExceptionInterface
{
    /**
     * @param string $errorCode
     * @param list<string> $chain
     * @param string|null $service
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
     * @param list<string> $chain
     * @param string|null $marker
     * @param string|null $fix
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
