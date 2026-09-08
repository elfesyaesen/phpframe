<?php

declare(strict_types=1);

namespace System\Container\Compiled;

use Closure;
use System\Container\Contract\ContainerInterface;
use System\Container\Contract\ScopeInterface;
use System\Container\Exception\ContainerException;
use System\Container\Exception\InvalidScopeException;
use System\Container\Exception\NotFoundException;
use System\Container\Lifetime\ScopeManager;

/**
 * Üretilen container sınıflarının elle yazılmış tabanı.
 *
 * DI-plan §24 — PRODUCTION IMMUTABILITY YAPISALDIR, FLAG DEĞİL:
 * bu sınıfın hiç mutator'ı yoktur. `bind()`, `singleton()`, `factory()` gibi
 * metotlar yalnızca ContainerBuilderInterface'te yaşar ve derlenmiş container
 * o arayüzü uygulamaz. Yani production'da tanım değiştirmek "engellenmiş"
 * değil, TEMSİL EDİLEMEZ.
 *
 * DI-plan §4/§37 — RUNTIME REFLECTION YOK:
 * bu sınıf ne ReflectionClass ne AutowireResolver yükler. Derlenmiş grafe
 * girmemiş bir id istenirse uyarı loglanıp devam EDİLMEZ — hata fırlatılır.
 * (Eski Container::reflectAndBuild() yalnızca uyarı logluyordu; bir servisin
 * derlemeden düşmesinin haftalarca fark edilmemesinin yolu buydu.)
 *
 * Üretilen alt sınıf şunları bildirir:
 *   const HASH       — derleme kimliği (§31)
 *   const EXTERNALS  — dışarıdan verilmesi gereken id'ler
 *   const SERVICES   — id => kurucu metot adı (§17)
 */
abstract class AbstractCompiledContainer implements ContainerInterface
{
    /**
     * id => kurucu metot adı. Alt sınıf ezer.
     *
     * @var array<string, string>
     */
    protected const SERVICES = [];

    /**
     * Dışarıdan verilmesi gereken servis id'leri. Alt sınıf ezer.
     *
     * @var array<string, true>
     */
    public const EXTERNALS = [];

    /** Derleme kimliği. Alt sınıf ezer. */
    public const HASH = '';

    /** @var array<string, mixed> */
    protected array $singletons = [];

    /** @var array<string, object> */
    protected array $externals;

    protected ScopeManager $scopes;

    /**
     * @param array<string, object> $externals instance() ile verilmiş canlı objeler
     */
    public function __construct(array $externals = [], ?ScopeManager $scopes = null)
    {
        $this->externals = $externals;
        $this->scopes = $scopes ?? new ScopeManager();

        $this->assertExternalsProvided();
    }

    public function get(string $id): mixed
    {
        $method = static::SERVICES[$id] ?? null;

        if ($method === null) {
            throw NotFoundException::notCompiled($id);
        }

        return $this->{$method}();
    }

    public function has(string $id): bool
    {
        return isset(static::SERVICES[$id]);
    }

    public function createScope(?string $name = null): ScopeInterface
    {
        return $this->scopes->begin($name ?? 'scope', $this);
    }

    public function scope(): ?ScopeInterface
    {
        return $this->scopes->active();
    }

    public function isCompiled(): bool
    {
        return true;
    }

    public function hash(): ?string
    {
        return static::HASH === '' ? null : static::HASH;
    }

    public function scopeManager(): ScopeManager
    {
        return $this->scopes;
    }

    /**
     * Derlenmiş servis id'leri — `container:list --compiled` için.
     *
     * @return list<string>
     */
    public function serviceIds(): array
    {
        return array_keys(static::SERVICES);
    }

    // ── Üretilen kodun kullandığı yardımcılar ──────────────────────

    /**
     * SCOPED servis çözümlemesi.
     *
     * Aktif scope yoksa SESSİZCE singleton'a terfi ETMEZ — terfi, tam olarak
     * scope mekanizmasının önlemek için var olduğu cross-request sızıntısını
     * üretir.
     *
     * Maliyet: çözümleme başına bir closure allocation. Bir istekte scoped
     * servis sayısı tek haneli olduğu için bu ölçülebilir bir yük değil;
     * §16'nın hedefi reflection ve graf yürüyüşünü sıfırlamaktı, o sağlanıyor.
     *
     * @param Closure(): mixed $factory
     */
    protected function scopedInstance(string $id, Closure $factory): mixed
    {
        $scope = $this->scopes->active();

        if ($scope === null) {
            throw InvalidScopeException::noActiveScope($id);
        }

        return $scope->remember($id, $factory);
    }

    /**
     * Factory destekli SINGLETON çözümlemesi.
     *
     * `??=` yerine array_key_exists kullanılır: bir factory meşru olarak null
     * döndürebilir (örn. opsiyonel bir sürücü) ve `??=` onu her çağrıda
     * yeniden üretirdi. Constructor tabanlı singleton'lar `??=` kullanır —
     * `new` asla null döndürmez.
     *
     * @param Closure(): mixed $factory
     */
    protected function sharedFactory(string $id, Closure $factory): mixed
    {
        if (array_key_exists($id, $this->singletons)) {
            return $this->singletons[$id];
        }

        return $this->singletons[$id] = $factory();
    }

    /** instance() ile verilmiş canlı obje. */
    protected function external(string $id): mixed
    {
        return $this->externals[$id] ?? throw ContainerException::missingExternal($id);
    }

    /**
     * Eksik external'ları KURULUM ANINDA bildirir.
     *
     * Bunu constructor'da yapmak kasıtlı: eksik bir config objesi, o servisi
     * ilk isteyen HTTP isteğinde 500 vermek yerine boot'ta patlar. Aynı hata,
     * ilk deploy denemesinde ve tüm isteklerde değil, tek yerde görünür.
     */
    private function assertExternalsProvided(): void
    {
        $missing = [];

        foreach (array_keys(static::EXTERNALS) as $id) {
            if (!isset($this->externals[$id])) {
                $missing[] = $id;
            }
        }

        if ($missing === []) {
            return;
        }

        throw new ContainerException(
            "[MISSING_EXTERNALS] Derlenmiş container eksik external'larla kuruldu.\n\n"
            . "  Verilmeyen:\n    " . implode("\n    ", $missing) . "\n\n"
            . "  Bu servisler `instance()` ile kaydedilmiş, yani canlı obje —\n"
            . "  derlenmiş dosyaya gömülemezler, kurulum sırasında verilmek zorundadır.\n"
            . "  (Bu sayede DB_PASS/SECRET_KEY üretilen PHP dosyasına asla yazılmaz.)\n"
            . "  Çözüm: Kernel'in externals dizisini kontrol et.",
            'MISSING_EXTERNALS',
            $missing,
        );
    }
}
