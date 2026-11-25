<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\LegacySession;
use App\Services\Routers\CapabilityException;
use App\Services\Routers\RouterManager;
use App\Services\Routers\RouterSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Shared helpers for the admin controllers.  Responses deliberately keep the
 * different envelopes used by the legacy Express modules instead of forcing a
 * single JSON shape.
 */
abstract class BaseController extends Controller
{
    protected ?string $routerError = null;

    protected function ok(array $data = []): JsonResponse
    {
        return ApiResponse::ok($data);
    }

    protected function success(array $extra = [], string $message = 'Success'): JsonResponse
    {
        return ApiResponse::success($extra, $message);
    }

    protected function fail(string $message, int $status = 400): JsonResponse
    {
        return ApiResponse::fail($message, $status);
    }

    /** `{success:false,message:...}` — no `error` key, as in many legacy handlers. */
    protected function plainFail(string $message, int $status = 400): JsonResponse
    {
        return ApiResponse::json(['success' => false, 'message' => $message], $status);
    }

    /** `{error:...}` — the shape used by the inline adminLegacy.js handlers. */
    protected function plainError(string $message, int $status = 500): JsonResponse
    {
        return ApiResponse::json(['error' => $message], $status);
    }

    protected function error(string $message = 'Server error. Please try again.'): JsonResponse
    {
        return ApiResponse::error($message);
    }

    protected function uid(Request $request): ?int
    {
        return LegacySession::id($request);
    }

    protected function clampInt(mixed $value, int $default, int $min, int $max): int
    {
        if (is_array($value) || is_object($value)) {
            return $default;
        }

        return max($min, min($max, (int) (is_numeric($value) ? $value : $default)));
    }

    protected function num(mixed $value, float $default = 0): float
    {
        return is_numeric($value) ? (float) $value : $default;
    }

    protected function boolInt(mixed $value): int
    {
        if ($value === false || $value === 0 || $value === '0' || $value === 'false') {
            return 0;
        }

        return $value ? 1 : 0;
    }

    /** Never expose router credentials in an admin response. */
    protected function stripSecrets(mixed $router): mixed
    {
        if ($router === null) {
            return null;
        }
        if (is_array($router)) {
            unset($router['password'], $router['wireguard_private_key'], $router['wireguard_preshared_key']);
            foreach ($router as $key => $value) {
                if (is_array($value) || is_object($value)) {
                    $router[$key] = $this->stripSecrets($value);
                }
            }

            return $router;
        }
        if (is_object($router)) {
            return $this->stripSecrets((array) $router);
        }

        return $router;
    }

    /**
     * Look up a router by numeric id or router_id.  Live operations only accept
     * registered (active) routers; management screens can still reach pending
     * rows to approve/remove them.
     */
    protected function resolveRouter(int|string|null $id, bool $activeOnly = true): ?array
    {
        if ($id === null || $id === '') {
            return null;
        }
        try {
            $query = DB::table('mikrotiks');
            if (is_numeric($id)) {
                $query->where(function ($q) use ($id): void {
                    $q->where('id', $id)->orWhere('router_id', (string) $id);
                });
            } else {
                $query->where('router_id', (string) $id);
            }
            if ($activeOnly) {
                $query->where('is_active', 1);
            }
            $row = $query->first();

            return $row ? (array) $row : null;
        } catch (Throwable $e) {
            $this->routerError = $e->getMessage();

            return null;
        }
    }

    protected function connectRouter(int|string|null $id, bool $activeOnly = true): ?RouterSession
    {
        $this->routerError = null;
        $row = $this->resolveRouter($id, $activeOnly);
        if ($row === null) {
            $this->routerError ??= 'Router not found';

            return null;
        }
        try {
            return app(RouterManager::class)->connect($row);
        } catch (Throwable $e) {
            $this->routerError = $e->getMessage();

            return null;
        }
    }

    /**
     * Invoke a typed driver operation.  Raw RouterOS commands are only used as
     * a fallback when the driver advertises the `raw` capability (Omada never
     * does), so unsupported platforms fail loudly instead of faking success.
     *
     * @return array{success: bool, data?: mixed, error?: string}
     */
    protected function callRouter(RouterSession $session, string $method, array $args = [], ?array $raw = null): array
    {
        $driver = $session->driver;
        try {
            if (! method_exists($driver, $method)) {
                throw new \BadMethodCallException("Router driver does not support {$method}");
            }

            return ['success' => true, 'data' => $driver->{$method}($session, ...$args)];
        } catch (Throwable $e) {
            $capabilityFailure = $e instanceof CapabilityException;
            if ($raw !== null && $driver->supports('raw')) {
                try {
                    return ['success' => true, 'data' => $driver->rawCommand($session, $raw[0], $raw[1] ?? [])];
                } catch (Throwable $rawError) {
                    return ['success' => false, 'error' => $rawError->getMessage()];
                }
            }

            return [
                'success' => false,
                'error' => $capabilityFailure
                    ? ($e->getMessage() ?: 'Operation not supported by this router platform')
                    : $e->getMessage(),
            ];
        }
    }

    protected function routerFailure(?string $fallback = 'Imeshindikana kupata hali ya router'): JsonResponse
    {
        return $this->plainFail($this->routerError ?: $fallback);
    }

    /** A small JSON-friendly view of a database row collection. */
    protected function rows(array $rows): array
    {
        return array_map(static fn ($row) => is_object($row) ? (array) $row : $row, $rows);
    }
}
