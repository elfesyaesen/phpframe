<?php

declare(strict_types=1);

namespace System\Container\Core;

use System\Container\Context\ContextualBinding;
use System\Container\Contract\ContainerBuilderInterface;

/**
 * `when(X)->needs(Y)->give(Z)` akıcı zincirinin ara adımları (DI-plan §12).
 *
 * NEDEN AYRI SINIF: `give()` çağrılana kadar binding TAMAMLANMAMIŞTIR.
 * Tek bir metotla (`contextual($consumer, $needs, $give)`) yazmak mümkün ama
 * üç konumlu argüman okunurluğu düşürür; akıcı zincir hangi parçanın ne
 * olduğunu isimleriyle söyler.
 *
 * Zincir YARIM BIRAKILIRSA hiçbir şey kaydedilmez — `give()` kaydı yapan
 * tek metottur. Bu, yanlışlıkla eksik bir binding kaydetmeyi imkânsız kılar.
 */
final class ContextualBindingBuilder
{
    public function __construct(
        private readonly ContainerBuilderInterface $builder,
        private readonly string $consumer,
        private readonly ?string $source = null,
    ) {}

    /**
     * Hangi bağımlılık için?
     *
     * @param string $abstract Tip adı (`Connection::class`) veya parametre
     *        adı (`'$timeout'`). Parametre adı, primitive'ler için tek yol
     *        ve aynı tipten birden fazla parametre varsa gerekli.
     */
    public function needs(string $abstract): ContextualNeedsBuilder
    {
        return new ContextualNeedsBuilder(
            $this->builder,
            $this->consumer,
            $abstract,
            $this->source,
        );
    }
}
