<?php

declare(strict_types=1);

namespace System\Console\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Command
{
    /**
     * @param string $name Komut adı (örn: "cache:clear")
     * @param string $description Komut açıklaması
     * @param string|null $usage Kullanım örneği
     * @param array<string> $aliases Alternatif isimler
     */
    public function __construct(
        public string $name,
        public string $description = '',
        public ?string $usage = null,
        public array $aliases = [],
    ) {}
}
