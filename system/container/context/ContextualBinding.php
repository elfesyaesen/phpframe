<?php

declare(strict_types=1);

namespace System\Container\Context;

/**
 * Tek bir bağlamsal binding kaydı (DI-plan §12).
 *
 * "X servisi Y'ye ihtiyaç duyduğunda Z'yi ver" — aynı arayüzün farklı
 * tüketicilerde farklı somut sınıfa çözülmesi.
 *
 *   OrderRepository  → Connection = primary
 *   ReportRepository → Connection = reporting
 *
 * NEDEN ARAYÜZ BINDING'İ YETMİYOR: `bind(Connection::class, Primary::class)`
 * GLOBAL bir karardır — tüm tüketiciler aynı örneği alır. Bağlamsal binding
 * kararı TÜKETİCİ BAŞINA verir.
 *
 * `$needs` iki şeyden biri olabilir:
 *   • tip adı  — `Connection::class` (en yaygın)
 *   • parametre adı — `'$timeout'` (primitive'ler için tek yol; tip adı
 *     ayırt edici olmadığı için)
 */
final readonly class ContextualBinding
{
    /**
     * @param class-string $consumer Bu bağımlılığı isteyen sınıf
     * @param string       $needs    Tip adı veya `'$parametreAdı'`
     * @param string|null  $giveId   Verilecek servis id'si
     * @param mixed        $giveValue Verilecek sabit değer (var_export güvenli)
     * @param bool         $isValue  true ise $giveValue, false ise $giveId geçerli
     * @param string|null  $source   Bildirimin yazıldığı yer (file:line)
     */
    public function __construct(
        public string $consumer,
        public string $needs,
        public ?string $giveId = null,
        public mixed $giveValue = null,
        public bool $isValue = false,
        public ?string $source = null,
    ) {}

    /** Parametre ADINA göre mi eşleşiyor (tipe göre değil)? */
    public function matchesParameterName(): bool
    {
        return str_starts_with($this->needs, '$');
    }

    /** `'$timeout'` → `'timeout'` */
    public function parameterName(): string
    {
        return ltrim($this->needs, '$');
    }

    public function describe(): string
    {
        $give = $this->isValue
            ? var_export($this->giveValue, true)
            : (string) $this->giveId;

        return $this->consumer . ' needs ' . $this->needs . ' → ' . $give;
    }
}
