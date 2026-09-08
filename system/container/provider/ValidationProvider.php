<?php

declare(strict_types=1);

namespace System\Container\Provider;

use System\Container\Contract\ContainerBuilderInterface;
use System\Validation\DataAccessor;
use System\Validation\RuleFactory;
use System\Validation\RuleParser;
use System\Validation\Validator;

/**
 * Doğrulama katmanı.
 */
final class ValidationProvider extends AbstractServiceProvider
{
    public function register(ContainerBuilderInterface $builder): void
    {
        $builder->singleton(DataAccessor::class);
        $builder->singleton(RuleParser::class);

        // RuleFactory container'ı alır ve bu MEŞRU bir locator kullanımıdır:
        // kural adından ('required', 'email') sınıfa dinamik eşleme yapar,
        // yani hangi kuralın kurulacağı runtime verisine bağlıdır.
        // DI-plan §37 service locator'ı yasaklar ama bu, kaçınılamaz
        // dinamik çözümlemenin tek noktada toplanmış hâli.
        $builder->singleton(RuleFactory::class);

        // TRANSIENT — kritik:
        //
        // Validator her doğrulamada hata durumu biriktirir. Singleton
        // olsaydı 1. isteğin doğrulama hataları 2. isteğe sızardı: geçerli
        // bir POST, önceki geçersiz isteğin hatalarıyla reddedilirdi.
        // Eski bootstrap onu singleton olarak kaydediyordu.
        $builder->transient(Validator::class);
    }

    public function provides(): array
    {
        return [
            DataAccessor::class,
            RuleParser::class,
            RuleFactory::class,
            Validator::class,
        ];
    }
}
