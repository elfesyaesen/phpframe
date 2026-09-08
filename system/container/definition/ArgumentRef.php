<?php

declare(strict_types=1);

namespace System\Container\Definition;

/**
 * Tek bir constructor argümanının çözümleme tarifi.
 *
 * `$parameter` yalnızca hata mesajları için taşınır ama vazgeçilmezdir:
 * "OrderService'in 3. argümanı çözülemedi" ile "OrderService::$currency
 * çözülemedi" arasındaki fark, hatayı bulma süresidir.
 */
final readonly class ArgumentRef
{
    /**
     * @param list<string>|null $ids TAGGED için etiketin çözümlenmiş üye
     *        listesi (derleme zamanında sabitlenir).
     */
    private function __construct(
        public ArgumentKind $kind,
        public ?string $id,
        public mixed $value,
        public string $parameter,
        public ?array $ids = null,
    ) {}

    /** @param class-string $id */
    public static function service(string $id, string $parameter): self
    {
        return new self(ArgumentKind::SERVICE, $id, null, $parameter);
    }

    /** Değerin var_export güvenli olduğu ÇAĞIRAN tarafından doğrulanmış olmalı. */
    public static function literal(mixed $value, string $parameter): self
    {
        return new self(ArgumentKind::LITERAL, null, $value, $parameter);
    }

    /** @param class-string $id */
    public static function external(string $id, string $parameter): self
    {
        return new self(ArgumentKind::EXTERNAL, $id, null, $parameter);
    }

    public static function container(string $parameter): self
    {
        return new self(ArgumentKind::CONTAINER, null, null, $parameter);
    }

    /**
     * Etiketli servis listesi (DI-plan §20).
     *
     * Üye listesi DERLEME ZAMANINDA sabitlenir; runtime'da etiket sorgusu
     * yapılmaz. Etikete yeni bir servis eklemek tanım değişikliğidir,
     * dolayısıyla derlemeyi bayatlatır ve `--check` kapısı yakalar.
     *
     * @param list<string> $ids Etiketin üyeleri (sıralı, deterministik)
     */
    public static function tagged(string $tag, array $ids, string $parameter): self
    {
        return new self(ArgumentKind::TAGGED, $tag, null, $parameter, $ids);
    }

    /**
     * Dekore edilen servisin İÇ katmanı (DI-plan §21).
     *
     * `$id` sarılan katmanın gerçek (dahili) id'sidir — dekoratörün kendi
     * id'si değil. Aksi halde dekoratör kendini sarar.
     */
    public static function decorated(string $innerId, string $parameter): self
    {
        return new self(ArgumentKind::DECORATED, $innerId, null, $parameter);
    }

    /** Bu argüman bağımlılık grafiğinde bir kenar üretir mi? */
    public function isEdge(): bool
    {
        return $this->kind === ArgumentKind::SERVICE
            || $this->kind === ArgumentKind::DECORATED;
    }

    /**
     * Bu argümanın ürettiği TÜM graf kenarları.
     *
     * TAGGED tek bir id değil bir LİSTE taşır; her üye bir kenardır.
     * Bunu ayrı ele almak zorunlu: aksi halde etiketli servislerin
     * bağımlılıkları grafe girmez ve döngü/scope doğrulaması onları
     * göremez — yani bir middleware'in scoped state yakalaması sessizce
     * geçerdi.
     *
     * @return list<string>
     */
    public function edges(): array
    {
        return match ($this->kind) {
            ArgumentKind::SERVICE, ArgumentKind::DECORATED => $this->id === null ? [] : [$this->id],
            ArgumentKind::TAGGED => $this->ids ?? [],
            default => [],
        };
    }

    /** CLI ve hata mesajları için kısa gösterim. */
    public function describe(): string
    {
        return match ($this->kind) {
            ArgumentKind::SERVICE   => (string) $this->id,
            ArgumentKind::EXTERNAL  => $this->id . ' (external)',
            ArgumentKind::CONTAINER => 'ContainerInterface',
            ArgumentKind::LITERAL   => self::describeLiteral($this->value),
            ArgumentKind::TAGGED    => '#' . $this->id . ' (' . count($this->ids ?? []) . ' üye)',
            ArgumentKind::DECORATED => $this->id . ' (dekore edilen iç katman)',
        };
    }

    private static function describeLiteral(mixed $value): string
    {
        return match (true) {
            $value === null   => 'null',
            is_bool($value)   => $value ? 'true' : 'false',
            is_string($value) => "'" . (strlen($value) > 24 ? substr($value, 0, 21) . '...' : $value) . "'",
            is_array($value)  => 'array(' . count($value) . ')',
            is_object($value) => $value::class,
            default           => (string) $value,
        };
    }
}
