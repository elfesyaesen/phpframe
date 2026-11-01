<?php

declare(strict_types=1);

namespace System\Routing\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class RouteGroup
{
    public function __construct(
        public string $prefix = '',
        public array $middleware = [],
        public array $where = [],
    ) {}

    public function toArray(): array
    {
        return [
            'prefix' => $this->prefix,
            'middleware' => $this->middleware,
            'where' => $this->where,
        ];
    }
}
