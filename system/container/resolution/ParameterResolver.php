<?php

declare(strict_types=1);

namespace System\Container\Resolution;

use Psr\Container\ContainerInterface as PsrContainerInterface;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use System\Container\Attribute\Config;
use System\Container\Attribute\Inject;
use System\Container\Attribute\Named;
use System\Container\Attribute\Tagged;
use System\Container\Binding\BindingRegistry;
use System\Container\Context\ContextualRegistry;
use System\Container\Contract\ContainerInterface as FrameContainerInterface;
use System\Container\Core\Container;
use System\Container\Tag\TagRegistry;
use System\Container\Definition\ArgumentRef;
use System\Container\Definition\DefinitionRegistry;
use System\Container\Definition\ExportGuard;
use System\Container\Exception\InvalidDefinitionException;
use System\Container\Exception\UnresolvableDependencyException;
use Throwable;

/**
 * Tek bir constructor parametresini bir ArgumentRef'e çevirir (DI-plan §9).
 *
 * Saf bir fonksiyondur: parametre + tüketici sınıf → tarif. Hedef servisin
 * VARLIĞINI kontrol etmez — o kontrol, grafik yürüyüşü o node'u ziyaret
 * ettiğinde yapılır. Bu ayrım sayesinde her hata TAM ZİNCİRİ taşır:
 * "OrderService → OrderRepository → Connection bulunamadı", tek başına
 * "Connection bulunamadı" değil.
 */
final class ParameterResolver
{
    /**
     * Container'ın kendisi olarak kabul edilen tipler.
     *
     * Container enjekte etmek genel olarak service locator'dır ve DI-plan §37
     * tarafından reddedilir; ancak `RuleFactory` gibi gerçek locator'lar
     * (kural adından sınıfa dinamik eşleme) meşru istisnadır.
     */
    private const CONTAINER_TYPES = [
        PsrContainerInterface::class => true,
        FrameContainerInterface::class => true,
        Container::class => true,
    ];

    public function __construct(
        private readonly BindingRegistry $bindings,
        private readonly ReflectionCache $reflection,
        private readonly ?DefinitionRegistry $definitions = null,
        /**
         * Bağlamsal binding'ler (DI-plan §12) ve etiketler (§20).
         *
         * Opsiyonel: bunlar olmadan resolver yalnızca tip tabanlı
         * autowiring yapar — Faz 1-4 davranışı. Verildiğinde §12 ve §20
         * devreye girer.
         */
        private readonly ?ContextualRegistry $contextual = null,
        private readonly ?TagRegistry $tags = null,
    ) {}

    /**
     * @param string       $consumer Parametreyi isteyen sınıf (hata mesajı için)
     * @param list<string> $chain    Çözümleme zinciri (hata mesajı için)
     */
    public function resolve(ReflectionParameter $parameter, string $consumer, array $chain = []): ArgumentRef
    {
        $name = $parameter->getName();

        // 1. Contextual binding override (DI-plan §12) — Faz 5 seam'i.
        $contextual = $this->resolveContextual($parameter, $consumer);
        if ($contextual !== null) {
            return $contextual;
        }

        // 2. Parametre attribute'ları (#[Inject], #[Named], #[Tagged]) — Faz 6 seam'i.
        //    Tip analizinden ÖNCE ki attribute tipi ezebilsin.
        $attributed = $this->resolveAttribute($parameter, $consumer);
        if ($attributed !== null) {
            return $attributed;
        }

        $type = $parameter->getType();

        // 3. Tek, builtin olmayan tip → servis.
        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $typeName = $type->getName();

            if (isset(self::CONTAINER_TYPES[$typeName])) {
                return ArgumentRef::container($name);
            }

            $target = $this->bindings->resolve($typeName);

            // OPSİYONEL BAĞIMLILIK: `?Foo $foo = null`.
            //
            // Nullable tip + AÇIK `= null` default'u, yazarın "bu servis
            // olmadan da çalışırım" beyanıdır. Bind edilmişse enjekte edilir;
            // edilmemişse default kullanılır.
            //
            // AYRIM ÖNEMLİ: default'u OLMAYAN nullable bir tip (`?Foo $foo`)
            // opsiyonel DEĞİLDİR — çağıran bir şey geçmek zorundadır — ve
            // orada sessizce null enjekte etmek DI-plan §10'un reddettiği
            // davranıştır (sessizce loglamayan bir logger, sessizce
            // doğrulamayan bir validator). O durum aşağıda SERVICE olarak
            // kalır ve binding yoksa derleme hatası verir.
            if ($type->allowsNull()
                && $parameter->isDefaultValueAvailable()
                && !$this->isKnown($target, $typeName)
            ) {
                return $this->resolveScalar($parameter, $consumer, $chain);
            }

            return ArgumentRef::service($target, $name);
        }

        // 4. Union / intersection tip.
        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            return $this->resolveComposite($type, $parameter, $consumer, $chain);
        }

        // 5. Builtin veya tipsiz → yalnızca default değerle çözülebilir.
        return $this->resolveScalar($parameter, $consumer, $chain);
    }

    /**
     * Bu id container tarafından biliniyor mu (açık tanım veya binding)?
     *
     * "Autowire edilebilir mi" DEĞİL "kayıtlı mı" sorulur: opsiyonel
     * bağımlılık kararı belirleyici olmak zorunda. Autowire edilebilirliği
     * ölçüt yapmak, tipin constructor'ına bağlı bulanık bir sonuç üretirdi.
     */
    private function isKnown(string $resolved, string $original): bool
    {
        return $this->bindings->has($original)
            || $this->definitions?->has($resolved) === true
            || $this->definitions?->has($original) === true;
    }

    /**
     * Builtin/tipsiz parametre.
     *
     * KRİTİK KARAR: nullable-default'suz bir parametre SESSİZCE null OLMAZ.
     * `?LoggerInterface $l` için null enjekte etmek, sessizce loglamayan bir
     * servis üretir — production'da haftalarca fark edilmeyen türden bir hata.
     * Sert hata, doğru çözüm önerisiyle birlikte verilir (DI-plan §10).
     */
    private function resolveScalar(ReflectionParameter $parameter, string $consumer, array $chain): ArgumentRef
    {
        $name = $parameter->getName();

        if ($parameter->isDefaultValueAvailable()) {
            try {
                $default = $parameter->getDefaultValue();
            } catch (Throwable $e) {
                // PHP 8.1+ initializer'da `new` ve sabit ifade kullanılabilir;
                // bazıları reflection ile okunamaz.
                throw InvalidDefinitionException::unexportableDefault(
                    $consumer,
                    $name,
                    'default değer okunamadı: ' . $e->getMessage(),
                );
            }

            $reason = ExportGuard::reject($default);
            if ($reason !== null) {
                throw InvalidDefinitionException::unexportableDefault($consumer, $name, $reason);
            }

            return ArgumentRef::literal($default, $name);
        }

        // Variadic parametre argüman almadan da geçerlidir (Faz 5: tagged expansion).
        if ($parameter->isVariadic()) {
            return ArgumentRef::literal([], $name);
        }

        $type = $parameter->getType();

        throw UnresolvableDependencyException::primitive(
            $consumer,
            $name,
            $type instanceof ReflectionNamedType ? $type->getName() : null,
            $chain,
        );
    }

    /**
     * Union/intersection: tanımı olan üyeleri topla. Tam bir tane ise onu
     * kullan; 0 veya 2+ ise container hangisini seçeceğini bilemez → hata.
     */
    private function resolveComposite(
        ReflectionUnionType|ReflectionIntersectionType $type,
        ReflectionParameter $parameter,
        string $consumer,
        array $chain,
    ): ArgumentRef {
        $name = $parameter->getName();
        $candidates = [];

        foreach ($type->getTypes() as $member) {
            if (!$member instanceof ReflectionNamedType || $member->isBuiltin()) {
                continue;
            }

            $resolved = $this->bindings->resolve($member->getName());

            $isKnown = $this->bindings->has($member->getName())
                || $this->definitions?->has($resolved) === true;

            if ($isKnown) {
                $candidates[] = $resolved;
            }
        }

        $candidates = array_values(array_unique($candidates));

        if (count($candidates) === 1) {
            return ArgumentRef::service($candidates[0], $name);
        }

        // Union'da default varsa (örn. `Foo|Bar $x = null`) onu kullanmak
        // belirsizliği çözmekten iyidir — geliştirici açıkça bir varsayılan
        // belirtmiş.
        if ($candidates === [] && $parameter->isDefaultValueAvailable()) {
            return $this->resolveScalar($parameter, $consumer, $chain);
        }

        throw UnresolvableDependencyException::ambiguousType($consumer, $name, $candidates, $chain);
    }

    /**
     * Bağlamsal binding (DI-plan §12).
     *
     * TİP ANALİZİNDEN ÖNCE çalışır ve onu EZER: bağlamsal binding'in tüm
     * amacı, aynı tipin farklı tüketicilerde farklı çözülmesidir.
     *
     * `give()` bir servis id'si de olabilir bir sabit değer de; ikinci biçim
     * primitive'ler için tek yoldur (`when(X)->needs('$timeout')->give(30)`).
     */
    private function resolveContextual(ReflectionParameter $parameter, string $consumer): ?ArgumentRef
    {
        if ($this->contextual === null || !$this->contextual->hasConsumer($consumer)) {
            return null;
        }

        $type = $parameter->getType();
        $typeName = $type instanceof ReflectionNamedType && !$type->isBuiltin()
            ? $type->getName()
            : null;

        $binding = $this->contextual->find($consumer, $typeName, $parameter->getName());

        if ($binding === null) {
            return null;
        }

        if ($binding->isValue) {
            $reason = ExportGuard::reject($binding->giveValue);

            if ($reason !== null) {
                throw InvalidDefinitionException::notExportable(
                    $consumer,
                    'when(' . $consumer . ')->needs(' . $binding->needs . ')->give(...)',
                    $reason,
                );
            }

            return ArgumentRef::literal($binding->giveValue, $parameter->getName());
        }

        return ArgumentRef::service(
            $this->bindings->resolve((string) $binding->giveId),
            $parameter->getName(),
        );
    }

    /**
     * Parametre attribute'ları (DI-plan §22).
     *
     * TİP ANALİZİNDEN ÖNCE, bağlamsal binding'den SONRA çalışır. Sıra
     * gerekçesi: bağlamsal binding tüketiciyi KURAN tarafın kararıdır
     * (provider), attribute ise TÜKETİLEN tarafın beyanıdır (sınıfın
     * kendisi). Provider'ın kararı daha dıştadır, dolayısıyla üstündür —
     * aksi halde bir sınıfa attribute yazmak, onu kuran provider'ın
     * kararını sessizce geçersiz kılardı.
     *
     * Attribute'lar YARDIMCI mekanizmadır; ana DI yolu constructor
     * injection + tip tabanlı autowiring'dir (DI-plan §22 son satırı).
     */
    private function resolveAttribute(ReflectionParameter $parameter, string $consumer): ?ArgumentRef
    {
        $name = $parameter->getName();

        // #[Inject(Foo::class)] — somut servisi açıkça seç.
        foreach ($parameter->getAttributes(Inject::class) as $attribute) {
            /** @var Inject $inject */
            $inject = $attribute->newInstance();

            return ArgumentRef::service($this->bindings->resolve($inject->id), $name);
        }

        // #[Tagged('tag')] — etiketin tüm üyeleri dizi olarak.
        foreach ($parameter->getAttributes(Tagged::class) as $attribute) {
            /** @var Tagged $tagged */
            $tagged = $attribute->newInstance();

            if ($this->tags === null || !$this->tags->has($tagged->tag)) {
                // Boş etiket SESSİZCE boş dizi OLMAZ: bir middleware
                // zincirinin veya listener listesinin sessizce boş kalması,
                // "neden hiçbir şey çalışmıyor?" sınıfı bir hatadır.
                throw UnresolvableDependencyException::unknownTag(
                    $consumer,
                    $name,
                    $tagged->tag,
                    $this->tags?->names() ?? [],
                );
            }

            return ArgumentRef::tagged(
                $tagged->tag,
                array_map(
                    fn(string $id): string => $this->bindings->resolve($id),
                    $this->tags->members($tagged->tag),
                ),
                $name,
            );
        }

        // #[Named('primary')] — tip + ad birleşimi.
        foreach ($parameter->getAttributes(Named::class) as $attribute) {
            /** @var Named $named */
            $named = $attribute->newInstance();

            $type = $parameter->getType();

            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                throw UnresolvableDependencyException::namedWithoutType($consumer, $name, $named->name);
            }

            return ArgumentRef::service(
                $this->bindings->resolve(Named::idFor($type->getName(), $named->name)),
                $name,
            );
        }

        // #[Config('key')] — literal() ile kaydedilmiş değer.
        foreach ($parameter->getAttributes(Config::class) as $attribute) {
            /** @var Config $config */
            $config = $attribute->newInstance();

            return ArgumentRef::service($config->key, $name);
        }

        return null;
    }
}
