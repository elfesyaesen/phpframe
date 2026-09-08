<?php

declare(strict_types=1);

namespace System\Container\Core;

use System\Container\Contract\ContainerInterface;
use System\Container\Contract\DisposableInterface;
use System\Container\Contract\ScopeInterface;
use System\Container\Exception\ContainerException;
use System\Container\Lifetime\ScopeManager;
use Throwable;

/**
 * Bir scope: SCOPED servislerin örneklerini tutan, sonunda kapatılan kap.
 */
final class Scope implements ScopeInterface
{
    /** @var array<string, mixed> */
    private array $instances = [];

    /**
     * Oluşturma sırası — dispose TERS bu sırayla çalışır, böylece bir servis
     * kapanırken kendisinden önce oluşmuş bağımlılıklarını hâlâ kullanabilir.
     *
     * @var list<string>
     */
    private array $order = [];

    private bool $disposed = false;

    public function __construct(
        private readonly string $name,
        private readonly ContainerInterface $container,
        private readonly ScopeManager $manager,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function get(string $id): mixed
    {
        $this->assertUsable($id);

        return $this->container->get($id);
    }

    public function has(string $id): bool
    {
        return $this->container->has($id);
    }

    public function remember(string $id, callable $factory): mixed
    {
        $this->assertUsable($id);

        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        $instance = $factory();

        $this->instances[$id] = $instance;
        $this->order[] = $id;

        return $instance;
    }

    public function set(string $id, mixed $instance): void
    {
        $this->assertUsable($id);

        if (!array_key_exists($id, $this->instances)) {
            $this->order[] = $id;
        }

        $this->instances[$id] = $instance;
    }

    /**
     * Scope'u kapatır.
     *
     * IDEMPOTENT: `Response::jsonResponse()` ve `BaseController::view()`
     * içindeki `exit` yüzünden dispose hem dispatch'in `finally` bloğundan
     * hem shutdown hook'undan çağrılabilir. İkinci çağrı sessizce döner.
     */
    public function dispose(): void
    {
        if ($this->disposed) {
            return;
        }

        // Bayrağı ÖNCE koy: bir dispose() gövdesi yeniden dispose tetiklerse
        // sonsuz döngüye girmesin.
        $this->disposed = true;

        foreach (array_reverse($this->order) as $id) {
            $instance = $this->instances[$id] ?? null;

            if ($instance instanceof DisposableInterface) {
                try {
                    $instance->dispose();
                } catch (Throwable) {
                    // Bir servisin temizliği diğerlerini engellemez. Teardown
                    // sırasında logger'ın kendisi kapanmış olabileceği için
                    // burada loglamıyoruz.
                }
            }
        }

        $this->instances = [];
        $this->order = [];

        $this->manager->release($this);

        // Sayaç (DI-plan §28): açılan/kapanan scope dengesi. Fark varsa
        // scoped servisler process sonuna kadar bellekte kalır — uzun
        // ömürlü CLI worker'larında bellek büyür.
        if ($this->container instanceof \System\Container\Core\Container) {
            $this->container->recorder()?->recordScopeDisposed();
        }
    }

    public function isDisposed(): bool
    {
        return $this->disposed;
    }

    /** Bu scope'ta tutulan servis id'leri (CLI/debug için). */
    public function ids(): array
    {
        return $this->order;
    }

    private function assertUsable(string $id): void
    {
        if ($this->disposed) {
            throw new ContainerException(
                "[SCOPE_DISPOSED] Kapatılmış '{$this->name}' scope'undan servis istendi: '{$id}'\n\n"
                . "  Scope kapandıktan sonra servis çözülemez. En sık sebep:\n"
                . "  bir shutdown handler, scope dispose edildikten SONRA çalıştı.\n"
                . "  Çözüm: ShutdownPriority sırasını kontrol et —\n"
                . "  SCOPE_DISPOSE en son çalışmak zorunda.",
                'SCOPE_DISPOSED',
                [$id],
                $id,
            );
        }
    }
}
