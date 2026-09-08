<?php

declare(strict_types=1);

namespace System\Container\Exception;

/**
 * Scope kuralı ihlali (DI-plan §8, §19).
 */
final class InvalidScopeException extends ContainerException
{
    /**
     * SCOPED bir servis aktif scope olmadan istendi.
     *
     * Bu ASLA sessizce singleton'a terfi ettirilmez: terfi, tam olarak scope
     * mekanizmasının önlemek için var olduğu cross-request sızıntısını üretir.
     */
    public static function noActiveScope(string $id, array $chain = []): self
    {
        $lines = self::chainWith($chain, $id);
        $lines[count($lines) - 1] .= '  (scoped)';

        return new self(
            self::render(
                'NO_ACTIVE_SCOPE',
                "Scoped servis '{$id}' aktif scope olmadan istendi.",
                $lines,
                'scope yok',
                "Bu servis istek/komut başına bir örnek olmak üzere tanımlı, ama\n"
                . "şu an açık bir scope yok. Muhtemel sebepler:\n"
                . "  • HTTP: istek scope'u açılmadan (veya dispose edildikten sonra) çözüldü\n"
                . "  • CLI:  komut scope'u dışında çözüldü\n"
                . "  • shutdown handler: scope dispose edildikten SONRA çalıştı\n"
                . "Çözüm: çözümlemeyi scope içine al, ya da shutdown sırasını düzelt\n"
                . "(bkz. ShutdownPriority — SCOPE_DISPOSE en son çalışmalı)."
            ),
            'NO_ACTIVE_SCOPE',
            $lines,
            $id,
        );
    }

    /**
     * Bir SINGLETON, SCOPED bir servise (doğrudan veya TRANSIENT üzerinden)
     * bağımlı — DI-plan §19'un yasakladığı kenar.
     *
     * Bu, container'ın önleyebileceği en tehlikeli hatadır: PHP-FPM'de
     * singleton worker ömrü boyunca yaşar, dolayısıyla 1. isteğin scoped
     * örneğini yakalar ve o worker'daki her sonraki isteğe onu servis eder.
     *
     * @param list<string> $lines Lifetime ve kaynak konumu ile biçimlenmiş zincir
     * @param list<string> $ids   Ham id zinciri
     */
    public static function singletonCapturesScoped(array $lines, array $ids): self
    {
        $singleton = $ids[0] ?? '?';
        $scoped = $ids[count($ids) - 1] ?? '?';

        return new self(
            self::render(
                'SINGLETON_CAPTURES_SCOPED',
                'Bir singleton, scoped bir servise bağımlı.',
                $lines,
                'scope ihlali',
                "{$singleton} tüm PHP-FPM worker ömrü boyunca yaşar; 1. isteğin\n"
                . "{$scoped} örneğini yakalar ve o worker'daki her sonraki isteğe\n"
                . "onu servis eder (DI-plan §8). Dev'de worker tek istek gördüğü\n"
                . "için görünmez; production'da veri sızdırır.\n"
                . "Çözüm 1: {$singleton}'ı scoped yap.\n"
                . "Çözüm 2: doğrudan enjekte etmek yerine bir locator/provider enjekte et\n"
                . "         (bkz. System\\Monitor\\RecorderLocator)."
            ),
            'SINGLETON_CAPTURES_SCOPED',
            $ids,
            $singleton,
        );
    }
}
