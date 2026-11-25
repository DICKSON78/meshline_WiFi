<?php

namespace App\Services\Routers;

/** Driver-neutral handle returned by RouterDriver::connect(). */
final class RouterSession
{
    public function __construct(
        public readonly RouterDriver $driver,
        public readonly array $router,
        public mixed $connection = null,
        public array $meta = [],
    ) {}

    public function routerId(): string
    {
        return (string) ($this->router['router_id'] ?? $this->router['id'] ?? '');
    }

    public function host(): string
    {
        return (string) ($this->router['host'] ?? '');
    }
}
