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

abstract class AbstractCompiledContainer implements ContainerInterface
{
    /**
     * @var array<string, string>
     */
    protected const SERVICES = [];

    /**
     * @var array<string, true>
     */
    public const EXTERNALS = [];

    public const HASH = '';

    /** @var array<string, mixed> */
    protected array $singletons = [];

    /** @var array<string, object> */
    protected array $externals;

    protected ScopeManager $scopes;

    /**
     * @param array<string, object> $externals
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
     * @return list<string>
     */
    public function serviceIds(): array
    {
        return array_keys(static::SERVICES);
    }

    /**
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
     * @param Closure(): mixed $factory
     */
    protected function sharedFactory(string $id, Closure $factory): mixed
    {
        if (array_key_exists($id, $this->singletons)) {
            return $this->singletons[$id];
        }

        return $this->singletons[$id] = $factory();
    }

    protected function external(string $id): mixed
    {
        return $this->externals[$id] ?? throw ContainerException::missingExternal($id);
    }

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
