<?php

namespace App\Http\Controllers\Router;

use App\Http\Controllers\Controller;
use App\Services\Routers\CapabilityException;
use App\Services\Routers\CollectorClient;
use App\Services\Routers\OmadaDriver;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Port of routes/routerFleet.js. */
class FleetController extends Controller
{
    use RouterResponseTrait;

    public function collectors(): JsonResponse
    {
        $collectors = CollectorClient::collectors();

        return ApiResponse::json([
            'success' => true,
            'collectors' => array_map(fn ($c) => [
                'key' => $c['key'],
                'connected' => (bool) ($c['connected'] ?? false),
                'lastHeartbeat' => $c['lastHeartbeat'] ?? null,
                'routers' => $c['routers'] ?? [],
            ], $collectors),
        ]);
    }

    public function routers(): JsonResponse
    {
        try {
            $registered = DB::table('mikrotiks')
                ->select('id', 'router_id', 'router_name', 'host', 'port', 'status',
                    'setup_status', 'ssid', 'model', 'latitude', 'longitude', 'last_seen', 'created_at')
                ->orderByDesc('created_at')->get()->all();
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage());
        }
        $registered = $this->stripSecretsMany($registered);
        $detected = [];
        foreach (CollectorClient::collectors() as $collector) {
            if (empty($collector['connected'])) {
                continue;
            }
            foreach ($collector['routers'] ?? [] as $router) {
                if (! is_array($router) || empty($router['connected'])) {
                    continue;
                }
                $identity = (string) ($router['identity'] ?? '');
                $rid = $identity !== ''
                    ? 'router-'.preg_replace('/[^a-zA-Z0-9]/', '-', strtolower($identity))
                    : null;
                $already = false;
                foreach (array_merge($registered, $detected) as $existing) {
                    if (($existing['router_id'] ?? '') === $rid || ($existing['host'] ?? '') === ($router['host'] ?? '')) {
                        $already = true;
                        break;
                    }
                }
                if ($already) {
                    continue;
                }
                $detected[] = [
                    'router_id' => $rid ?: 'detected-'.($router['host'] ?? 'unknown'),
                    'router_name' => $identity ?: 'Router @ '.($router['host'] ?? ''),
                    'host' => $router['host'] ?? '',
                    'port' => (int) ($router['port'] ?? 8728),
                    'status' => 'detected',
                    'setup_status' => 'pending',
                    'model' => $router['model'] ?? null,
                    'firmware' => $router['firmware'] ?? null,
                    'last_seen' => $router['lastSeen'] ?? null,
                    'detected_by' => $collector['key'] ?? null,
                ];
            }
        }

        return ApiResponse::ok(['routers' => array_merge($registered, $detected)]);
    }

    public function detect(Request $request): JsonResponse
    {
        $key = (string) $request->input('collectorKey', '');
        if ($key === '') {
            return ApiResponse::fail('collectorKey is required');
        }
        if (! CollectorClient::isConnected($key)) {
            return ApiResponse::fail('Collector not connected', 409);
        }
        CollectorClient::sendRaw($key, ['type' => 'scan']);
        $probe = CollectorClient::send($key, '/system/identity/print', [], null, 20);
        if (! $probe['ok']) {
            return ApiResponse::ok([
                'message' => 'Detection triggered on collector. Results arrive via heartbeat.',
            ]);
        }

        return ApiResponse::ok(['message' => 'Collector reachable.', 'identity' => $probe['data']]);
    }

    /**
     * Proxy a MikroTik API command.  With a collectorKey the command travels
     * through POST /send; otherwise it is executed by the local driver.
     */
    public function mikrotik(Request $request): JsonResponse
    {
        $key = (string) $request->input('collectorKey', '');
        $path = (string) $request->input('path', '');
        $args = (array) $request->input('args', []);
        $routerRef = $request->input('router');
        if ($path === '') {
            return ApiResponse::fail('path is required');
        }

        // Omada has no raw command channel — fail clearly instead of guessing.
        if ($routerRef !== null) {
            $row = $this->routerRow((string) (is_array($routerRef) ? ($routerRef['router_id'] ?? '') : $routerRef));
            if ($row && strtolower((string) ($row['driver'] ?? env('ROUTER_DRIVER', 'mikrotik'))) === 'omada') {
                return ApiResponse::fail('Raw MikroTik commands are not supported on Omada routers. Use the typed Omada endpoints.', 501);
            }
        }
        if ($key !== '') {
            if (! CollectorClient::isConnected($key)) {
                return ApiResponse::json(['success' => false, 'data' => null, 'error' => 'collector not connected']);
            }
            $result = CollectorClient::send($key, $path, $args, $routerRef);

            return ApiResponse::json([
                'success' => $result['ok'], 'data' => $result['data'], 'error' => $result['error'],
            ]);
        }

        // Direct driver: pick the router from `router` or the first active one.
        $id = is_array($routerRef) ? (string) ($routerRef['router_id'] ?? '') : (string) ($routerRef ?? '');
        $row = $id !== '' ? $this->routerRow($id) : null;
        if (! $row && $id === '') {
            try {
                $row = DB::table('mikrotiks')->where('is_active', 1)->orderBy('id')->first();
            } catch (\Throwable $e) {
                $row = DB::table('mikrotiks')->orderBy('id')->first();
            }
        }
        if (! $row) {
            return ApiResponse::fail('Router not found or collectorKey is required', 404);
        }
        $session = null;
        try {
            $session = $this->manager()->connect((array) $row);
            if ($session->driver instanceof OmadaDriver) {
                return ApiResponse::fail('Raw MikroTik commands are not supported on Omada routers.', 501);
            }
            $data = $session->driver->rawCommand($session, $path, $args);

            return ApiResponse::ok(['data' => $data]);
        } catch (CapabilityException $e) {
            return ApiResponse::fail($e->getMessage(), 501);
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage());
        } finally {
            if ($session) {
                $session->driver->disconnect($session);
            }
        }
    }

    public function register(Request $request): JsonResponse
    {
        $routerID = (string) $request->input('routerID', '');
        $routerName = (string) $request->input('routerName', '');
        $host = (string) $request->input('host', '');
        if ($routerID === '' || $routerName === '' || $host === '') {
            return ApiResponse::fail('routerID, routerName and host are required');
        }
        if (DB::table('mikrotiks')->where('router_id', $routerID)->exists()) {
            return ApiResponse::fail('Router already registered', 409);
        }
        try {
            $password = $request->input('password', '');
            DB::table('mikrotiks')->insert([
                'router_id' => $routerID, 'router_name' => $routerName, 'host' => $host,
                'port' => (int) $request->input('port', 8728),
                'user' => (string) $request->input('user', 'admin'),
                'password' => $password ? \App\Support\Encryption::encrypt((string) $password) : '',
                'ssid' => $request->input('ssid', ''),
                'location_name' => $request->input('locationName'),
                'latitude' => $request->input('latitude'), 'longitude' => $request->input('longitude'),
                'status' => 'offline', 'setup_status' => 'detected',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return ApiResponse::success([], 'Router registered. It will be configured on next connect.');
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage());
        }
    }
}
