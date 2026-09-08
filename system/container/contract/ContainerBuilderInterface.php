<?php

declare(strict_types=1);

namespace System\Container\Contract;

use System\Container\Binding\BindingRegistry;
use System\Container\Context\ContextualBinding;
use System\Container\Context\ContextualRegistry;
use System\Container\Core\ContextualBindingBuilder;
use System\Container\Decorator\DecoratorRegistry;
use System\Container\Definition\DefinitionRegistry;
use System\Container\Definition\LazyRef;
use System\Container\Lifetime\Lifetime;
use System\Container\Tag\TagRegistry;

/**
 * Build-time tanım yazma API'si (DI-plan §33).
 *
 * Bu arayüz YALNIZCA build/compile zamanında var olur. Runtime container'da
 * karşılığı yoktur — production'da tanım değiştirmek imkânsızdır (DI-plan §24)
 * çünkü derlenmiş container bu arayüzü uygulamaz.
 *
 * Tüm mutator'lar fluent (`static` döner) ve kilit kontrolü yapar.
 */
interface ContainerBuilderInterface
{
    /**
     * Servisi kaydeder. $concrete verilirse id → concrete alias kenarı da
     * kaydedilir (interface binding, DI-plan §11).
     *
     * @param class-string      $id
     * @param class-string|null $concrete null ise concrete = id
     */
    public function bind(string $id, ?string $concrete = null, Lifetime $lifetime = Lifetime::TRANSIENT): static;

    /** bind(..., Lifetime::SINGLETON) kısayolu. */
    public function singleton(string $id, ?string $concrete = null): static;

    /** bind(..., Lifetime::SCOPED) kısayolu — istek/komut başına tek örnek. */
    public function scoped(string $id, ?string $concrete = null): static;

    /** bind(..., Lifetime::TRANSIENT) kısayolu — her çözümlemede yeni örnek. */
    public function transient(string $id, ?string $concrete = null): static;

    /**
     * Factory ile üretilen servis (DI-plan §13). PDO, Redis, HTTP client gibi
     * external resource'lar için.
     *
     * DERLENEBİLİRLİK: $factory bir REFERANS olmak zorunda —
     * [Sınıf::class, 'staticMetot'] | Sınıf::class (invokable/create) |
     * CompilableFactoryInterface. Closure yalnızca dev'de çalışır ve
     * `container:validate` tarafından hata olarak raporlanır.
     *
     * @param class-string                          $id
     * @param callable|array{0:class-string,1:string}|class-string $factory
     * @param list<class-string>                    $dependsOn
     *        Factory gövdesindeki $c->get() çağrıları derleyiciye GÖRÜNMEZ.
     *        Grafe girmesi, döngü ve scope doğrulamasına dahil olması gereken
     *        bağımlılıkları burada bildir. Boş bırakmak meşrudur (kasıtlı
     *        opaklık — döngü kırma) ama `container:validate` bunu notice
     *        olarak raporlar, yani opaklık sessiz kalmaz.
     */
    public function factory(
        string $id,
        callable|array|string $factory,
        Lifetime $lifetime = Lifetime::SINGLETON,
        array $dependsOn = [],
    ): static;

    /**
     * Hazır bir objeyi servis olarak verir (DI-plan §7 INSTANCE).
     *
     * Canlı obje derlenmiş PHP'ye gömülemez; derlemede "external" olarak
     * bildirilir ve container kurulurken dışarıdan verilir. Typed config
     * objelerinin container'a giriş yolu budur (DI-plan §25) — böylece
     * DB_PASS/SECRET_KEY üretilen dosyaya asla yazılmaz.
     *
     * @param class-string $id
     */
    public function instance(string $id, object $value): static;

    /**
     * var_export güvenli bir değeri servis olarak verir (scalar, array, null,
     * enum case). Derlenmiş koda doğrudan gömülür — external gerekmez.
     */
    public function literal(string $id, mixed $value): static;

    /**
     * Saf yönlendirme: $id istendiğinde $target çözülür. Tanım üretmez.
     *
     * @param class-string $id
     * @param class-string $target
     */
    public function alias(string $id, string $target): static;

    /**
     * Ertelenmiş referans üretir — döngü kırmak için kullanılan, BİLDİRİLMİŞ
     * kenar. Constructor'a `Closure` yerine tipli `LazyRef` geçmek, kesilen
     * kenarı araçlara görünür kılar.
     */
    public function lazyRef(string $id): LazyRef;

    /**
     * Kasıtlı olarak kesilmiş bir döngü kenarını bildirir (DI-plan §18).
     *
     * Bildirilmiş kenar compile hatası vermez; bildirim kaldırıldığı hâlde
     * döngü sürüyorsa hata verilir. Yani "bu döngüyü biliyorum" bilgisi
     * yorumda değil, doğrulanabilir veri olarak durur.
     */
    public function cutEdge(string $from, string $to, string $reason): static;

    // ── Faz 5: bağlamsal binding, etiketleme, dekoratör ───────────

    /**
     * Bağlamsal binding zinciri başlatır (DI-plan §12).
     *
     *   $b->when(OrderRepository::class)->needs(Connection::class)->give('primary');
     *   $b->when(HttpClient::class)->needs('$timeout')->giveValue(30);
     *
     * Aynı arayüzün farklı tüketicilerde farklı çözülmesini sağlar. Global
     * `bind()` bunu yapamaz — o tüm tüketiciler için tek karar verir.
     *
     * @param class-string $consumer
     */
    public function when(string $consumer): ContextualBindingBuilder;

    /**
     * Bağlamsal binding'i doğrudan kaydeder.
     *
     * `when()->needs()->give()` zincirinin son adımı bunu çağırır; ayrıca
     * derleyicinin attribute taramasından gelen kayıtlar için kullanılır.
     */
    public function addContextualBinding(ContextualBinding $binding): static;

    /**
     * Servisleri bir etiketle işaretler (DI-plan §20).
     *
     *   $b->tag([AuthMiddleware::class, RateLimitMiddleware::class], 'http.middleware');
     *
     * Etiketli liste `#[Tagged('http.middleware')]` ile enjekte edilir.
     * SIRA KORUNUR (alfabetik sıralanmaz): etiketlerin en yaygın kullanımı
     * pipeline zincirleridir ve orada sıra anlamsaldır.
     *
     * @param list<class-string> $ids
     */
    public function tag(array $ids, string $tag): static;

    /**
     * Bir servisi dekoratörle sarar (DI-plan §21).
     *
     *   $b->decorate(CacheInterface::class, MetricsCache::class);
     *   $b->decorate(CacheInterface::class, LoggingCache::class);
     *
     * SON eklenen EN DIŞTA olur: LoggingCache → MetricsCache → RedisCache.
     *
     * Zincir derleme zamanında düzleştirilir (iç katmanlar dahili id'lere
     * taşınır), dolayısıyla runtime'da dekoratör araması veya proxy yoktur.
     *
     * @param class-string $id
     * @param class-string $decorator
     */
    public function decorate(string $id, string $decorator): static;

    /** Örtük (reflection) çözümlemeyi aç/kapat. */
    public function autowire(bool $enabled = true): static;

    /**
     * Builder'ı dondurur. Provider'lar koştuktan sonra çağrılır; geç kalmış
     * bir bind() dev'de de production'da olacağı gibi patlar.
     */
    public function lock(): static;

    public function isLocked(): bool;

    /** Bu id için açık bir tanım veya binding var mı (autowire'a bakmaz)? */
    public function has(string $id): bool;

    public function definitions(): DefinitionRegistry;

    public function bindings(): BindingRegistry;

    /**
     * instance() ile verilen canlı objeler.
     *
     * @return array<class-string, object>
     */
    public function externals(): array;

    /**
     * Bildirilmiş döngü-kesme kenarları.
     *
     * @return array<string, array{from: string, to: string, reason: string}>
     */
    public function cutEdges(): array;

    /** Bağlamsal binding'ler (DI-plan §12). */
    public function contextual(): ContextualRegistry;

    /** Servis etiketleri (DI-plan §20). */
    public function tags(): TagRegistry;

    /** Dekoratör zincirleri (DI-plan §21). */
    public function decorators(): DecoratorRegistry;

    /** Dev runtime container'ı kurar (reflection destekli, kilitli). */
    public function build(): ContainerInterface;
}
