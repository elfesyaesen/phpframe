<?php

declare(strict_types=1);

namespace System\Container\Exception;

use System\Container\Compilation\Diagnostic;

/**
 * Derleme başarısız — bir veya daha fazla Diagnostic toplandı.
 *
 * Compiler istisna FIRLATMAZ, Diagnostic TOPLAR: tek koşuda tüm problemlerin
 * görülmesi, ilk hatada durup 20 kez derlemekten çok daha iyidir. Bu istisna
 * yalnızca toplama bittikten sonra, programatik çağrı yolunda fırlatılır
 * (CLI komutu bunun yerine Diagnostic tablosunu basar).
 */
final class CompilationException extends ContainerException
{
    /**
     * @param list<Diagnostic> $diagnostics
     */
    public function __construct(
        public readonly array $diagnostics,
        string $message = '',
    ) {
        $errors = array_values(array_filter(
            $diagnostics,
            static fn(Diagnostic $d): bool => $d->isError()
        ));

        if ($message === '') {
            $count = count($errors);
            $message = "[COMPILATION_FAILED] Container derlemesi {$count} hata ile başarısız.\n";
            foreach (array_slice($errors, 0, 10) as $d) {
                $message .= "\n" . $d->render() . "\n";
            }
            if ($count > 10) {
                $message .= "\n  ... ve " . ($count - 10) . " hata daha."
                    . " Tümü için: php frame container:validate\n";
            }
        }

        parent::__construct($message, 'COMPILATION_FAILED');
    }

    /** Derlenmiş container dosyası yok veya bayat — production'da hard fatal. */
    public static function notCompiled(string $expectedFile): self
    {
        return new self([], self::render(
            'CONTAINER_NOT_COMPILED',
            'Production derlenmiş container olmadan çalışamaz.',
            [$expectedFile],
            'dosya yok',
            "Production'da runtime'da derleme YAPILMAZ (DI-plan §32) ve reflection\n"
            . "fallback YOKTUR (DI-plan §4). Derlenmiş container deploy artifact'ının\n"
            . "parçası olmak zorunda.\n"
            . "Çözüm: deploy build adımına ekle:\n"
            . "  php frame container:compile"
        ));
    }

    /** Pointer'daki hash mevcut tanımlarla uyuşmuyor — bayat derleme. */
    public static function stale(string $compiledHash, string $currentHash): self
    {
        return new self([], self::render(
            'CONTAINER_STALE',
            'Derlenmiş container bayat.',
            ['derlenmiş: ' . $compiledHash, 'güncel:    ' . $currentHash],
            'hash uyuşmuyor',
            "Servis tanımları, config veya PHP sürümü derlemeden sonra değişti.\n"
            . "Bu uyarı DEĞİL hatadır: bayat container son deploy'un tanımlarını\n"
            . "koşar ve yeni servisler sessizce kaybolur.\n"
            . "Çözüm:\n"
            . "  php frame container:compile"
        ));
    }
}
