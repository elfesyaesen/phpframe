<?php

declare(strict_types=1);

namespace System\Container\Provider;

use System\Container\Contract\ContainerBuilderInterface;
use System\Database\Database;
use System\Database\MigrationRunner;
use System\Database\Schema;

/**
 * Veritabanı katmanı.
 *
 * Üçünün de artık FACTORY'ye ihtiyacı YOK — hepsi autowire edilebilir, çünkü
 * bağımlılıkları tipli objeler (DatabaseConfig, MonitorConfig, RecorderLocator,
 * AppConfig). Eskiden global sabit okudukları için constructor'ları elle
 * kurulmak zorundaydı.
 *
 * Üçü de SINGLETON: PDO bağlantısı worker ömrü boyunca paylaşılır. Bağlantı
 * artık LAZY açıldığı için (bkz. Database::getConnection) singleton olmak bir
 * bedel getirmez — hiç sorgu yapılmayan bir istekte bağlantı hiç açılmaz.
 */
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
