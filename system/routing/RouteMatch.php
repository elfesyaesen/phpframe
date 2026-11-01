<?php

declare(strict_types=1);

namespace System\Routing;

class RouteMatch
{
    public function __construct(
        public string $method,
        public string $path,
        public array $action,
        public array $params = [],
        public array $middleware = [],
        public ?string $name = null,
    ) {}

    public function getController(): string
    {
        return $this->action[0] ?? '';
    }

    public function getMethod(): string
    {
        return $this->action[1] ?? '__invoke';
    }

    public function hasMiddleware(): bool
    {
        return !empty($this->middleware);
    }

    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'path' => $this->path,
            'action' => $this->action,
            'params' => $this->params,
            'middleware' => $this->middleware,
            'name' => $this->name,
        ];
    }

    public static function fromArray(array $data, array $params = []): self
    {
        return new self(
            method: $data['method'] ?? 'GET',
            path: $data['path'] ?? $data['uri'] ?? '/',
            action: $data['action'] ?? [],
            params: $params,
            middleware: $data['middleware'] ?? [],
            name: $data['name'] ?? null,
        );
    }
}
