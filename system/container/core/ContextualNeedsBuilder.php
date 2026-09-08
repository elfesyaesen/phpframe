<?php

declare(strict_types=1);

namespace System\Container\Core;

use System\Container\Context\ContextualBinding;
use System\Container\Contract\ContainerBuilderInterface;

/**
 * `when(X)->needs(Y)` zincirinin son adımı (DI-plan §12).
 *
 * `give()` ve `giveValue()` ayrı metotlar: birincisi bir SERVİS id'si alır,
 * ikincisi SABİT bir değer. Tek metotla `give(mixed)` yazmak, bir string'in
 * "sınıf adı mı yoksa değerin kendisi mi" olduğunu belirsiz bırakırdı —
 * `give('primary')` bir servis id'si mi, yoksa `'primary'` string'i mi?
 */
final class ContextualNeedsBuilder
{
    public function __construct(
        private readonly ContainerBuilderInterface $builder,
        private readonly string $consumer,
        private readonly string $needs,
        private readonly ?string $source = null,
    ) {}

    /**
     * Bu bağımlılık için şu SERVİSİ ver.
     *
     * @param class-string|string $id
     */
    public function give(string $id): ContainerBuilderInterface
    {
        $this->builder->addContextualBinding(new ContextualBinding(
            consumer: $this->consumer,
            needs: $this->needs,
            giveId: $id,
            source: $this->source,
        ));

        return $this->builder;
    }

    /**
     * Bu bağımlılık için şu SABİT DEĞERİ ver.
     *
     * Değer var_export güvenli olmak zorunda (scalar, array, null, enum
     * case) — derlenmiş koda gömülür.
     */
    public function giveValue(mixed $value): ContainerBuilderInterface
    {
        $this->builder->addContextualBinding(new ContextualBinding(
            consumer: $this->consumer,
            needs: $this->needs,
            giveValue: $value,
            isValue: true,
            source: $this->source,
        ));

        return $this->builder;
    }
}
