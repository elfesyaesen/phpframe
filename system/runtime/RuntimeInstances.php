<?php

declare(strict_types=1);

namespace System\Runtime;

use System\Config\ConfigFactory;

final class RuntimeInstances
{
    /**
     * @param bool $validate
     * @return array<class-string, object>
     */
    public static function build(
        string $appRoot,
        ShutdownRegistry $shutdown,
        bool $validate = true,
    ): array {
        $configs = ConfigFactory::forRoot($appRoot)->all($validate);

        return [
            ...$configs,
            ShutdownRegistry::class => $shutdown,
        ];
    }
}
