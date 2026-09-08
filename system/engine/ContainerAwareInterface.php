<?php

declare(strict_types=1);

namespace System\Engine;

use Psr\Container\ContainerInterface;

/**
 * Container'a ihtiyaç duyan ama DI ile constructor-resolve EDİLMEYEN bileşenler
 * için (factory/`new` ile üretilen validation rule'ları, middleware'ler).
 *
 * Bu bileşenlere container, onları üreten nokta (RuleFactory, MiddlewareRegistry) tarafından
 * setter ile verilir. Böylece global statik service-locator'a (eski
 * BaseController::$container) gerek kalmaz; bağımlılık akışı açık olur.
 *
 * Not: Controller'lar DI ile resolve edildiğinden onlar container'ı constructor'dan
 * alır (bu arayüze ihtiyaç duymaz).
 */
interface ContainerAwareInterface
{
    public function setContainer(ContainerInterface $container): void;
}
