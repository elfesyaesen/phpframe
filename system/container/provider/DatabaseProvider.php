<?php

declare(strict_types=1);

namespace System\Container\Provider;

use System\Container\Contract\ContainerBuilderInterface;
use System\Database\Database;
use System\Database\MigrationRunner;
use System\Database\Schema;

final class DatabaseProvider extends AbstractServiceProvider
{
    public function register(ContainerBuilderInterface $builder): void
    {
        $builder->singleton(Database::class);
        $builder->singleton(Schema::class);
        $builder->singleton(MigrationRunner::class);
    }

    public function provides(): array
    {
        return [Database::class, Schema::class, MigrationRunner::class];
    }
}
