<?php

namespace App\Http\Controllers\Router;

use App\Http\Controllers\Controller;
use App\Services\Routers\ProvisioningService;
use App\Services\Routers\RepairService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Port of routes/zeroTouch.js.  These routes are CSRF-exempt and guarded by
 * the shared ROUTER_REGISTRATION_SECRET (fallback RADIUS_SECRET).
 */
class ZeroTouchController extends Controller
{
    public function __construct(
        private readonly ProvisioningService $provisioning = new ProvisioningService(),
        private readonly RepairService $repairs = new RepairService(),
    ) {}

    private function secretValid(Request $request): bool
    {
        $expected = ProvisioningService::registrationSecret();
        $provided = (string) ($request->input('secret') ?? $request->query('secret') ?? '');
        if ($expected === '') {
            return false;
        }

        return hash_equals($expected, $provided);
    }

    private function authError(): JsonResponse
    {
        if (ProvisioningService::registrationSecret() === '') {
            return ApiResponse::error('Server registration secret not configured', 500);
        }

        return ApiResponse::fail('Invalid registration secret', 401);
    }

    private function payload(Request $request): array
    {
        $input = $request->all() + $request->query();
        $get = fn (...$keys) => collect($keys)->map(fn ($k) => $input[$k] ?? null)->first(fn ($v) => $v !== null);

        return [
            'serial' => $get('serial', 'serial_number'),
            'identity' => $get('identity'),
            'model' => $get('model'),
            'firmware' => $get('firmware'),
            'publicIp' => $get('public_ip', 'publicIp'),
            'routerId' => $get('router_id', 'device_id'),
            'location_id' => $get('location_id'),
            'ssid' => $get('ssid'),
        ];
    }

    public function register(Request $request): Response|JsonResponse
    {
        if (! $this->secretValid($request)) {
            return $this->authError();
        }
        try {
            $payload = $this->payload($request);
            $autoConnect = in_array($request->input('auto_connect'), ['1', 'true', true, 1], true);
            $result = $this->provisioning->registerRouter($payload);
            $script = $result['script'];
            $status = $result['pending'] ? 'pending' : 'ready';
            if ($autoConnect) {
                $connected = $this->provisioning->connectRouter((string) $result['router']['router_id'], $payload);
                $script = $connected['script'];
                $status = 'configuring';
            }

            return $this->script($script, [
                'X-HotBando-Router-ID' => $result['router']['router_id'] ?? '',
                'X-HotBando-WG-IP' => $result['assignedIp'] ?? '',
                'X-HotBando-Status' => $status,
            ]);
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage());
        }
    }

    public function bootstrap(): Response|JsonResponse
    {
        try {
            return $this->script(ProvisioningService::generateBootstrapScript(), ['X-HotBando-Script' => 'bootstrap']);
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage());
        }
    }

    public function registerJson(Request $request): JsonResponse
    {
        if (! $this->secretValid($request)) {
            return $this->authError();
        }
        try {
            $result = $this->provisioning->registerRouter($this->payload($request));

            return ApiResponse::ok([
                'status' => $result['pending'] ? 'pending' : 'ready',
                'router' => [
                    'id' => $result['router']['router_id'] ?? null,
                    'name' => $result['router']['router_name'] ?? null,
                    'assignedIp' => $result['assignedIp'] ?? null,
                    'wireguardIp' => $result['assignedIp'] ?? null,
                ],
                'server' => [
                    'publicIp' => $result['serverPublicIp'] ?? ProvisioningService::publicHost(),
                    'port' => $result['wgPort'] ?? null,
                    'network' => $result['wgNetwork'] ?? null,
                ],
            ]);
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage());
        }
    }

    public function provision(Request $request, string $routerID): Response|JsonResponse
    {
        if (! $this->secretValid($request)) {
            return $this->authError();
        }
        try {
            $result = $this->provisioning->provisionRouter([
                'routerId' => $routerID,
                'ssid' => $request->input('ssid'),
            ]);
            $headers = [
                'X-HotBando-Router-ID' => $routerID,
                'X-HotBando-Status' => ! empty($result['pending']) ? 'pending' : 'ready',
            ];
            if (! empty($result['assignedIp'])) {
                $headers['X-HotBando-WG-IP'] = $result['assignedIp'];
            }

            return $this->script($result['script'], $headers);
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage());
        }
    }

    /** One-time, short-lived repair script download for the router itself. */
    public function repairScript(string $token): Response
    {
        $source = $this->repairs->scriptForToken($token);
        if ($source === null) {
            return response('not found', 404, ['content-type' => 'text/plain; charset=utf-8']);
        }

        return $this->script($source, ['Cache-Control' => 'no-store']);
    }

    public function repairStatus(string $routerID): JsonResponse
    {
        return ApiResponse::ok($this->repairs->repairState($routerID));
    }

    public function heartbeat(Request $request): JsonResponse
    {
        if (! $this->secretValid($request)) {
            return $this->authError();
        }
        $routerID = (string) ($request->input('router_id') ?? $request->input('routerId') ?? '');
        if ($routerID === '') {
            return ApiResponse::fail('router_id required');
        }
        try {
            DB::table('mikrotiks')->where('router_id', $routerID)->update([
                'last_seen' => now(),
                'status' => DB::raw("CASE WHEN setup_status = 'configured' THEN 'online' ELSE status END"),
            ]);
            $row = DB::table('mikrotiks')->where('router_id', $routerID)
                ->first(['setup_status', 'ssid', 'wireguard_ip', 'updated_at']);
            $payload = [
                'success' => true, 'ok' => true,
                'needsConfig' => ! $row || ($row->setup_status ?? '') !== 'configured',
                'configVersion' => $row->updated_at ?? null,
                'ssid' => $row->ssid ?? null,
            ];

            // Repair delivery must never block the heartbeat reply.  The
            // shutdown callback runs after Laravel has sent the response.
            register_shutdown_function(function () use ($routerID): void {
                try {
                    $this->repairs->maybeApplyRepair($routerID);
                } catch (\Throwable $e) {
                    report($e);
                }
            });

            return ApiResponse::ok($payload);
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage());
        }
    }

    private function script(string $body, array $headers = []): Response
    {
        return response($body, 200, array_merge([
            'Content-Type' => 'text/plain; charset=utf-8',
        ], $headers));
    }
}
