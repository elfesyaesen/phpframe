<?php

declare(strict_types=1);

namespace System\Container\Provider;

use System\Container\Contract\ContainerBuilderInterface;
use System\Validation\DataAccessor;
use System\Validation\RuleFactory;
use System\Validation\RuleParser;
use System\Validation\Validator;

final class ValidationProvider extends AbstractServiceProvider
{
    public function register(ContainerBuilderInterface $builder): void
    {
        $builder->singleton(DataAccessor::class);
        $builder->singleton(RuleParser::class);

        $builder->singleton(RuleFactory::class);

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
