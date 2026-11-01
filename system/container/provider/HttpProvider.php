<?php

declare(strict_types=1);

namespace System\Container\Provider;

use System\Container\Contract\ContainerBuilderInterface;
use System\Http\Request;
use System\Http\Response;

final class HttpProvider extends AbstractServiceProvider
{
    public function register(ContainerBuilderInterface $builder): void
    {
        $builder->scoped(Request::class);

        $builder->scoped(Response::class);
    }

    public function provides(): array
    {
        return [Request::class, Response::class];
    }
}
