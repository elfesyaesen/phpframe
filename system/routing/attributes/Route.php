<?php

declare(strict_types=1);

namespace System\Routing\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Route
{
    public array $methods;

    public function __construct(
        public string $path,
        array|string $methods = ['GET'],
        public ?string $name = null,
        public array $middleware = [],
        public array $where = [],
        public int $priority = 0,
    ) {
        $this->methods = is_string($methods) ? [$methods] : $methods;
    }

    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'methods' => $this->methods,
            'name' => $this->name,
            'middleware' => $this->middleware,
            'where' => $this->where,
            'priority' => $this->priority,
        ];
    }
}
