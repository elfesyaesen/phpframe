<?php

declare(strict_types=1);

namespace System\Container\Compilation;

use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * PHP dosyalarından FQCN çıkarır (token analizi ile).
 *
 * Mantık eski System\Engine\ContainerCompiler::fqcnFromFile()'dan alınmıştır
 * ve orada olduğu gibi `Foo::class` ile anonim `new class` durumlarını
 * ayıklar. Dosya `include` EDİLMEZ — derleme sırasında rastgele dosyaları
 * çalıştırmak yan etki üretir.
 *
 * ÖNEMLİ KAPSAM NOTU: bu tarayıcı artık servis kümesini BELİRLEMEZ. Yalnızca
 * root ADAYLARINI (controller, komut, provider) sayar. Eski compiler her
 * modüldeki her dosyayı tarayıp autowire etmeye çalışıyordu; yeni sert-hata
 * kuralı altında bu, hiç servis olmayan sınıflarda (primitive constructor'lı
 * modeller, DTO'lar) derlemeyi düşürürdü.
 */
final class ClassScanner
{
    /**
     * Dizindeki tüm PHP dosyalarından FQCN listesi.
     *
     * @param list<string> $skipDirNames Atlanacak dizin adları
     * @return list<string>
     */
    public function scanDirectory(string $directory, array $skipDirNames = []): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $skip = array_fill_keys($skipDirNames, true);
        $classes = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());

            foreach (array_keys($skip) as $name) {
                if (str_contains($path, '/' . $name . '/')) {
                    continue 2;
                }
            }

            $fqcn = $this->fqcnFromFile($file->getPathname());
            if ($fqcn !== null) {
                $classes[] = $fqcn;
            }
        }

        sort($classes); // deterministik sıra: dosya sistemi sırası taşınabilir değil

        return $classes;
    }

    /**
     * Dosyadaki ilk sınıf bildiriminin FQCN'i. interface/trait/enum ve
     * `Foo::class` / anonim sınıf durumları atlanır.
     */
    public function fqcnFromFile(string $path): ?string
    {
        $code = @file_get_contents($path);

        if ($code === false || $code === '') {
            return null;
        }

        $tokens = PhpToken::tokenize($code);
        $count = count($tokens);
        $namespace = '';

        for ($i = 0; $i < $count; $i++) {
            $id = $tokens[$i]->id;

            if ($id === T_NAMESPACE) {
                $namespace = trim($this->readName($tokens, $i, $count), '\\');
                continue;
            }

            if ($id === T_CLASS) {
                // `Foo::class` ve anonim `new class` durumlarını ayıkla.
                $previous = $this->previousSignificant($tokens, $i);
                if ($previous !== null && in_array($previous->id, [T_DOUBLE_COLON, T_NEW], true)) {
                    continue;
                }

                $next = $this->nextSignificant($tokens, $i);
                if ($next !== null && $next->id === T_STRING) {
                    return $namespace !== '' ? $namespace . '\\' . $next->text : $next->text;
                }
            }
        }

        return null;
    }

    /**
     * @param array<int, PhpToken> $tokens
     */
    private function readName(array $tokens, int $from, int $count): string
    {
        $name = '';

        for ($j = $from + 1; $j < $count; $j++) {
            $text = $tokens[$j]->text;

            if ($text === ';' || $text === '{') {
                break;
            }

            if (in_array($tokens[$j]->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $name .= $text;
            }
        }

        return $name;
    }

    /**
     * @param array<int, PhpToken> $tokens
     */
    private function previousSignificant(array $tokens, int $i): ?PhpToken
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (!$tokens[$j]->isIgnorable()) {
                return $tokens[$j];
            }
        }

        return null;
    }

    /**
     * @param array<int, PhpToken> $tokens
     */
    private function nextSignificant(array $tokens, int $i): ?PhpToken
    {
        $count = count($tokens);

        for ($j = $i + 1; $j < $count; $j++) {
            if (!$tokens[$j]->isIgnorable()) {
                return $tokens[$j];
            }
        }

        return null;
    }
}
