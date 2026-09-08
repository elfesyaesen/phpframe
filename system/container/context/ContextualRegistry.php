<?php

declare(strict_types=1);

namespace System\Container\Context;

/**
 * Bağlamsal binding'lerin kaydı (DI-plan §12).
 *
 * Arama iki adımlı ve SIRA ÖNEMLİ:
 *   1. Parametre ADI ile eşleşme (`'$timeout'`) — daha özgül
 *   2. TİP ile eşleşme (`Connection::class`)
 *
 * Parametre adının önce denenmesi kasıtlı: bir sınıfın aynı tipten iki
 * parametresi olabilir (`Connection $primary, Connection $replica`) ve tip
 * eşleşmesi ikisini ayırt edemez. Ad eşleşmesi her zaman daha spesifiktir.
 *
 * Kalıtım DESTEKLENMEZ (yalnızca tam sınıf eşleşmesi): bir alt sınıfın
 * ebeveyninin bağlamsal binding'ini miras alması, "hangi binding devrede"
 * sorusunu kalıtım hiyerarşisine bağlı hâle getirir ve DI-plan §4'ün
 * deterministik çözümleme hedefini bulanıklaştırır.
 */
final class ContextualRegistry
{
    /**
     * consumer => needs => binding
     *
     * @var array<string, array<string, ContextualBinding>>
     */
    private array $bindings = [];

    public function add(ContextualBinding $binding): void
    {
        $this->bindings[$binding->consumer][$binding->needs] = $binding;
    }

    /**
     * Tüketici + (tip, parametre adı) için bağlamsal binding.
     *
     * @param class-string $consumer
     */
    public function find(string $consumer, ?string $type, string $parameterName): ?ContextualBinding
    {
        $forConsumer = $this->bindings[$consumer] ?? null;

        if ($forConsumer === null) {
            return null;
        }

        // 1. Parametre adı — daha özgül, önce denenir.
        $byName = $forConsumer['$' . $parameterName] ?? null;
        if ($byName !== null) {
            return $byName;
        }

        // 2. Tip.
        return $type === null ? null : ($forConsumer[$type] ?? null);
    }

    public function hasConsumer(string $consumer): bool
    {
        return isset($this->bindings[$consumer]);
    }

    public function isEmpty(): bool
    {
        return $this->bindings === [];
    }

    /**
     * Tüm binding'ler, deterministik sırayla (hash ve CLI çıktısı için).
     *
     * @return list<ContextualBinding>
     */
    public function all(): array
    {
        $consumers = $this->bindings;
        ksort($consumers);

        $out = [];
        foreach ($consumers as $needs) {
            ksort($needs);
            foreach ($needs as $binding) {
                $out[] = $binding;
            }
        }

        return $out;
    }

    public function count(): int
    {
        return array_sum(array_map('count', $this->bindings));
    }
}
