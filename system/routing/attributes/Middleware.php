<?php

declare(strict_types=1);

namespace System\Routing\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Middleware
{
    public array $middleware;

    public function __construct(string|array ...$middleware)
    {
        $flattened = [];
        foreach ($middleware as $item) {
            if (is_array($item)) {
                $flattened = [...$flattened, ...$item];
            } else {
                $flattened[] = $item;
            }
        }
        $this->middleware = $flattened;
    }

    public function toArray(): array
    {
        return $this->middleware;
    }
}
