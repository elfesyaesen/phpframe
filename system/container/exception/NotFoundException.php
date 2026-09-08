<?php

declare(strict_types=1);

namespace System\Container\Exception;

use Psr\Container\NotFoundExceptionInterface;

/**
 * İstenen id için container'da kayıt yok (PSR-11 NotFoundExceptionInterface).
 */
final class NotFoundException extends ContainerException implements NotFoundExceptionInterface
{
    /**
     * @param list<string> $chain Bu servisi isteyen zincir
     */
    public static function for(string $id, array $chain = []): self
    {
        $lines = self::chainWith($chain, $id);

        return new self(
            self::render(
                'SERVICE_NOT_FOUND',
                "Container'da '{$id}' kaydı yok.",
                $lines,
                'bulunamadı',
                "Böyle bir sınıf yok, ya da autowire kapalı ve açık bir tanım verilmemiş.\n"
                . "Çözüm: sınıf adını/namespace'i doğrula, ya da bir provider'da bind et:\n"
                . "  \$b->singleton({$id}::class);"
            ),
            'SERVICE_NOT_FOUND',
            $lines,
            $id,
        );
    }

    /**
     * Derlenmiş container'da bilinmeyen id. Production'da reflection fallback
     * YOKTUR (DI-plan §4, §37) — bu durum bir bug'dır, yavaş yol değil.
     *
     * @param list<string> $chain
     */
    public static function notCompiled(string $id, array $chain = []): self
    {
        $lines = self::chainWith($chain, $id);

        return new self(
            self::render(
                'SERVICE_NOT_COMPILED',
                "'{$id}' derlenmiş container'da yok.",
                $lines,
                'derlenmemiş',
                "Production'da runtime reflection fallback YOKTUR (DI-plan §4).\n"
                . "Bu servis hiçbir root'tan erişilemediği için derleme kapsamına\n"
                . "girmemiş olabilir. Çözüm:\n"
                . "  php frame container:list --uncompiled   # neden atlandığını gör\n"
                . "  php frame container:compile             # yeniden derle"
            ),
            'SERVICE_NOT_COMPILED',
            $lines,
            $id,
        );
    }
}
