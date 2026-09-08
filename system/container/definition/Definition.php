<?php

declare(strict_types=1);

namespace System\Container\Definition;

use System\Container\Lifetime\Lifetime;

/**
 * Container'ın temel yapı taşı (DI-plan §6): bir servisin nasıl kurulacağının
 * tam, veri hâlindeki tarifi.
 *
 * Tamamen readonly ve tamamen veri olması kasıtlıdır — compiler bunu PHP
 * koduna çevirebilmek için üzerinde davranış olmayan bir tarif ister.
 */
final readonly class Definition
{
    /**
     * @param class-string       $id        Çözümleme anahtarı (interface veya sınıf)
     * @param class-string       $concrete  Gerçekten örneklenecek sınıf
     * @param list<ArgumentRef>  $arguments Constructor argümanları (factory varsa boş)
     * @param list<class-string> $dependsOn Factory'nin BİLDİRDİĞİ bağımlılıklar.
     *        Factory gövdesi derleyiciye opaktır; grafe girmesi gereken
     *        bağımlılıklar burada bildirilir.
     * @param bool               $lazy      Faz 1-4'te no-op (her şey lazy).
     *        Faz 5'te eager instantiation / proxy seam'i.
     * @param bool               $autowired Örtük mü (reflection) yoksa açık mı (builder)?
     * @param string|null        $source    Tanımın yazıldığı yer, "provider/CacheProvider.php:31".
     *        Her hata mesajı bunu yazar; build-time'da toplamak ucuz, hata
     *        ayıklarken paha biçilmez.
     */
    public function __construct(
        public string $id,
        public string $concrete,
        public Lifetime $lifetime,
        public ?FactoryRef $factory = null,
        public array $arguments = [],
        public array $dependsOn = [],
        public bool $lazy = true,
        public bool $autowired = false,
        public ?string $source = null,
    ) {}

    /**
     * Argümanları doldurulmuş bir kopya döndürür.
     *
     * `bind()` reflection'a hiç dokunmaz; argümanlar AutowireResolver
     * tarafından sonradan doldurulur (dev'de lazy, compiler'da eager). Bu
     * ayrım build()'i reflection geçişinden kurtarır ve reflection'ın
     * kapsamlı çalıştığı tek yerin compiler olmasını sağlar.
     *
     * @param list<ArgumentRef> $arguments
     */
    public function withArguments(array $arguments): self
    {
        return new self(
            $this->id,
            $this->concrete,
            $this->lifetime,
            $this->factory,
            $arguments,
            $this->dependsOn,
            $this->lazy,
            $this->autowired,
            $this->source,
        );
    }

    public function withLifetime(Lifetime $lifetime): self
    {
        return new self(
            $this->id,
            $this->concrete,
            $lifetime,
            $this->factory,
            $this->arguments,
            $this->dependsOn,
            $this->lazy,
            $this->autowired,
            $this->source,
        );
    }

    /**
     * Bu tanımın bağımlılık grafiğindeki çıkış kenarları.
     *
     * Factory tanımları için yalnızca BİLDİRİLMİŞ `dependsOn` sayılır —
     * gövdedeki `$c->get()` çağrıları görünmez. Bu bir kusur değil,
     * döngü kırma mekanizmasının ta kendisidir (bkz. LazyRef).
     *
     * @return list<class-string>
     */
    public function edges(): array
    {
        if ($this->factory !== null) {
            return $this->dependsOn;
        }

        $edges = [];

        foreach ($this->arguments as $argument) {
            // `ArgumentRef::edges()` kullanılır, `isEdge()` + `->id` DEĞİL:
            // TAGGED bir argüman tek bir id değil BİR LİSTE taşır ve her
            // üye ayrı bir kenardır. Tek id varsayımı etiketli servislerin
            // bağımlılıklarını grafın dışında bırakır — yani bir
            // middleware'in scoped state yakalaması sessizce geçerdi.
            foreach ($argument->edges() as $target) {
                $edges[] = $target;
            }
        }

        return array_values(array_unique([...$edges, ...$this->dependsOn]));
    }

    /** INSTANCE lifetime'ı: değer dışarıdan gelir, container kurmaz. */
    public function isExternal(): bool
    {
        return $this->lifetime === Lifetime::INSTANCE
            && $this->arguments === [];
    }

    public function isFactoryBacked(): bool
    {
        return $this->factory !== null;
    }

    /** CLI tablolarında gösterilen "nasıl kurulur" özeti. */
    public function describeConstruction(): string
    {
        if ($this->factory !== null) {
            return $this->factory->describe();
        }

        if ($this->lifetime === Lifetime::INSTANCE) {
            return 'instance (external)';
        }

        return $this->concrete === $this->id ? 'new ' . $this->concrete : $this->concrete;
    }
}
