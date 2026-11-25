<?php

namespace App\Services\Routers;

use App\Support\Encryption;
use Illuminate\Support\Facades\DB;

/** Resolves the right driver for a row in `mikrotiks` and decrypts secrets. */
class RouterManager
{
    /** @var array<string, RouterDriver> */
    private array $drivers = [];

    public function register(RouterDriver $driver): void
    {
        $this->drivers[$driver->name()] = $driver;
    }

    public function driver(string $name): RouterDriver
    {
        if (! isset($this->drivers[$name])) {
            throw new \InvalidArgumentException("Unknown router driver: {$name}");
        }

        return $this->drivers[$name];
    }

    public function row(int|string $id): ?array
    {
        $row = DB::table('mikrotiks')
            ->where('id', $id)
            ->orWhere('router_id', (string) $id)
            ->first();

        return $row ? (array) $row : null;
    }

    public function driverFor(array $router): RouterDriver
    {
        $name = strtolower((string) ($router['driver'] ?? env('ROUTER_DRIVER', 'mikrotik')));

        return $this->driver($name === 'omada' ? 'omada' : 'mikrotik');
    }

    public function connect(array $router): RouterSession
    {
        if (! empty($router['password']) && Encryption::isEncrypted($router['password'])) {
            $router['password'] = Encryption::decrypt($router['password']);
        }
        foreach (['omada_password', 'omada_client_secret'] as $secret) {
            if (! empty($router[$secret]) && Encryption::isEncrypted($router[$secret])) {
                $router[$secret] = Encryption::decrypt($router[$secret]);
            }
        }

        return $this->driverFor($router)->connect($router);
    }

    public function connectById(int|string $id): ?RouterSession
    {
        $row = $this->row($id);

        return $row ? $this->connect($row) : null;
    }
}
