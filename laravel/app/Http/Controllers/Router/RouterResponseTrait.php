<?php

namespace App\Http\Controllers\Router;

use App\Services\Routers\CapabilityException;
use App\Services\Routers\RouterManager;
use App\Services\Routers\RouterSession;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

trait RouterResponseTrait
{
    /** The legacy Express controller removed these from every router payload. */
    protected function stripSecrets(mixed $router): mixed
    {
        if (! is_array($router) && ! is_object($router)) {
            return $router;
        }
        $row = (array) $router;
        foreach (['password', 'omada_password', 'omada_client_secret', 'wireguard_private_key', 'wireguard_preshared_key'] as $key) {
            unset($row[$key]);
        }

        return $row;
    }

    protected function stripSecretsMany(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->stripSecrets($row);
        }

        return $out;
    }

    /** Resolve an active router row without ever inventing an id. */
    protected function routerRow(string $id): ?array
    {
        $query = \Illuminate\Support\Facades\DB::table('mikrotiks')
            ->where('router_id', $id);
        if (ctype_digit($id)) {
            $query->orWhere('id', (int) $id);
        }
        try {
            $row = $query->first();
        } catch (\Throwable $e) {
            return null;
        }
        if (! $row) {
            return null;
        }
        $row = (array) $row;
        if (array_key_exists('is_active', $row) && (int) $row['is_active'] !== 1) {
            return null;
        }

        return $row;
    }

    protected function manager(): RouterManager
    {
        return app(RouterManager::class);
    }

    protected function session(string $id): ?RouterSession
    {
        $row = $this->routerRow($id);

        return $row ? $this->manager()->connect($row) : null;
    }

    /** Run a driver primitive and convert capability/connection errors to JSON. */
    protected function callDriver(string $id, string $method, array $args = []): JsonResponse
    {
        $session = null;
        try {
            $session = $this->session($id);
            if (! $session) {
                return ApiResponse::fail('Router not found or not active', 404);
            }
            $result = $session->driver->{$method}($session, ...$args);

            return ApiResponse::ok(['data' => $result]);
        } catch (CapabilityException $e) {
            return ApiResponse::fail($e->getMessage(), 501, ['capability' => $e->capability]);
        } catch (\Throwable $e) {
            return ApiResponse::error('Router operation failed: '.$e->getMessage());
        } finally {
            if ($session) {
                try {
                    $session->driver->disconnect($session);
                } catch (\Throwable $e) {
                    // ignore
                }
            }
        }
    }
}
