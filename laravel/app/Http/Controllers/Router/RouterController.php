<?php

namespace App\Http\Controllers\Router;

use App\Http\Controllers\Controller;
use App\Services\Routers\CapabilityException;
use App\Services\Routers\ProvisioningService;
use App\Services\Routers\WireGuardService;
use App\Support\ApiResponse;
use App\Support\Encryption;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Port of routes/routerRoutes.js + the router methods of adminController.js. */
class RouterController extends Controller
{
    use RouterResponseTrait;

    public function index(): JsonResponse
    {
        try {
            $routers = DB::table('mikrotiks as m')
                ->select('m.*')
                ->selectRaw('COALESCE(l.name, m.location_name) as location_name')
                ->selectRaw('COALESCE(l.city, m.location_city) as city')
                ->selectRaw('COALESCE(l.region, m.location_region) as region')
                ->selectRaw('b.name as branch_name')
                ->selectRaw('(SELECT COUNT(DISTINCT u.id) FROM users u LEFT JOIN hotspot_sessions hs ON hs.user_id = u.id AND (hs.router_id = m.router_id OR hs.router_id = m.id) WHERE u.role = ? AND (u.last_router_id = m.router_id OR u.last_router_id = m.id OR hs.router_id = m.router_id OR hs.router_id = m.id)) as customer_count', ['customer'])
                ->selectRaw('(SELECT COUNT(DISTINCT u.id) FROM users u LEFT JOIN hotspot_sessions hs ON hs.user_id = u.id AND (hs.router_id = m.router_id OR hs.router_id = m.id) WHERE u.role = ? AND (u.last_router_id = m.router_id OR u.last_router_id = m.id OR hs.router_id = m.router_id OR hs.router_id = m.id) AND u.usage_until IS NOT NULL AND u.usage_until > NOW()) as active_bundle_users', ['customer'])
                ->leftJoin('locations as l', 'm.location_id', '=', 'l.id')
                ->leftJoin('branches as b', 'l.branch_id', '=', 'b.id')
                ->orderByDesc('m.id')
                ->get()
                ->all();
        } catch (\Throwable $e) {
            $routers = DB::table('mikrotiks')->orderByDesc('id')->get()->all();
        }
        $safe = $this->stripSecretsMany($routers);

        return ApiResponse::ok(['routers' => $safe, 'data' => $safe, 'count' => count($safe)]);
    }

    public function health(Request $request): JsonResponse
    {
        $id = (string) ($request->query('routerID') ?: 'router-default');
        $row = $this->routerRow($id);
        if (! $row) {
            return ApiResponse::ok(['data' => $this->offlineHealth($id)]);
        }
        $session = null;
        try {
            $session = $this->manager()->connect($row);
            $info = $session->driver->identity($session);
            $users = count($session->driver->activeSessions($session));
            $health = [
                'routerID' => $id,
                'status' => 'online',
                'connectedUsers' => $users,
                'cpu' => $info['cpu_load'] ?? 0,
                'memory' => $info['memory_usage'] ?? 0,
                'uptime' => $info['uptime'] ?? '0s',
                'version' => $info['version'] ?? 'unknown',
                'identity' => $info['name'] ?? 'unknown',
                'lastSeen' => $row['last_seen'] ?? gmdate('c'),
            ];

            return ApiResponse::ok(['data' => $health]);
        } catch (\Throwable $e) {
            return ApiResponse::ok(['data' => $this->offlineHealth($id, $row['last_seen'] ?? null)]);
        } finally {
            if ($session) {
                $session->driver->disconnect($session);
            }
        }
    }

    private function offlineHealth(string $id, mixed $lastSeen = null): array
    {
        return [
            'routerID' => $id, 'status' => 'offline', 'connectedUsers' => 0, 'cpu' => 0,
            'memory' => 0, 'uptime' => '0s', 'version' => 'unknown', 'identity' => 'unknown',
            'lastSeen' => $lastSeen ?? gmdate('c'),
        ];
    }

    public function statusAll(): JsonResponse
    {
        $rows = [];
        try {
            $rows = DB::table('mikrotiks')->get()->all();
        } catch (\Throwable $e) {
            // database not migrated yet
        }
        $wg = (new WireGuardService())->getWireGuardPeers();
        $peers = [];
        foreach ($wg['peers'] ?? [] as $peer) {
            $peers[(string) $peer['routerId']] = $peer;
        }
        $statuses = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $id = (string) ($row['router_id'] ?? $row['id'] ?? '');
            $health = $this->healthFromRow($row, $id);
            $tunnelOnline = ($peers[$id]['status'] ?? '') === 'online';
            $online = ($health['status'] ?? '') === 'online' || $tunnelOnline;
            $statuses[] = [
                'routerId' => $id,
                'routerName' => $row['router_name'] ?? $id,
                'isOnline' => $online,
                'apiReachable' => ($health['status'] ?? '') === 'online',
                'totalUsers' => (int) ($row['total_users'] ?? 0),
                'activeUsers' => ($health['status'] ?? '') === 'online' ? ($health['connectedUsers'] ?? 0) : null,
                'cpu' => ($health['status'] ?? '') === 'online' ? ($health['cpu'] ?? 0) : null,
                'memory' => ($health['status'] ?? '') === 'online' ? ($health['memory'] ?? 0) : null,
                'uptime' => ($health['status'] ?? '') === 'online' ? ($health['uptime'] ?? 'N/A')
                    : ($tunnelOnline ? 'WireGuard connected' : 'N/A'),
            ];
        }

        return ApiResponse::ok(['routers' => $statuses]);
    }

    private function healthFromRow(array $row, string $id): array
    {
        $session = null;
        try {
            $session = $this->manager()->connect($row);
            $info = $session->driver->identity($session);

            return [
                'status' => 'online',
                'connectedUsers' => count($session->driver->activeSessions($session)),
                'cpu' => $info['cpu_load'] ?? 0,
                'memory' => $info['memory_usage'] ?? 0,
                'uptime' => $info['uptime'] ?? '0s',
            ];
        } catch (\Throwable $e) {
            return $this->offlineHealth($id);
        } finally {
            if ($session) {
                $session->driver->disconnect($session);
            }
        }
    }

    public function show(string $id): JsonResponse
    {
        $row = DB::table('mikrotiks')->where('id', $id)->orWhere('router_id', $id)->first();
        if (! $row) {
            return ApiResponse::fail('Router hakupatikana', 404);
        }

        return ApiResponse::ok(['router' => $this->stripSecrets($row)]);
    }

    public function liveStatus(string $routerID): JsonResponse
    {
        $session = null;
        try {
            $session = $this->session($routerID);
            if (! $session) {
                throw new \RuntimeException('Router not found');
            }
            $driver = $session->driver;
            $status = method_exists($driver, 'fullStatus') ? $driver->fullStatus($session) : $driver->identity($session);

            return ApiResponse::ok(['status' => $status]);
        } catch (CapabilityException $e) {
            return ApiResponse::fail($e->getMessage(), 501);
        } catch (\Throwable $e) {
            return ApiResponse::ok(['status' => [
                'routerID' => $routerID, 'identity' => 'Unknown', 'isOnline' => false,
                'error' => $e->getMessage(), 'lastChecked' => gmdate('c'),
            ]]);
        } finally {
            if ($session) {
                $session->driver->disconnect($session);
            }
        }
    }

    public function sessions(string $routerID): JsonResponse
    {
        return $this->driverResult($routerID, 'activeSessions', [], 'data', 'count');
    }

    public function hotspotSessions(string $routerID): JsonResponse
    {
        return $this->driverResult($routerID, 'activeSessions', [], 'sessions', 'count');
    }

    public function hotspotUsers(string $routerID): JsonResponse
    {
        return $this->driverResult($routerID, 'hotspotUsers', [], 'users');
    }

    public function kick(Request $request, string $routerID): JsonResponse
    {
        $sessionId = (string) ($request->input('sessionId') ?? '');
        if ($sessionId === '') {
            return ApiResponse::fail('Session ID inahitajika');
        }
        $session = null;
        try {
            $session = $this->session($routerID);
            if (! $session) {
                return ApiResponse::fail('Router not found', 404);
            }
            $ok = $session->driver->kickSession($session, $sessionId);

            return ApiResponse::json(['success' => $ok, 'message' => $ok ? 'User disconnected' : 'Kick failed']);
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

    public function bindings(string $routerID): JsonResponse
    {
        $session = null;
        try {
            $session = $this->session($routerID);
            if (! $session) {
                return ApiResponse::fail('Router not found', 404);
            }
            $rows = method_exists($session->driver, 'ipBindings') ? $session->driver->ipBindings($session) : [];

            return ApiResponse::ok(['bindings' => $rows]);
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage());
        } finally {
            if ($session) {
                $session->driver->disconnect($session);
            }
        }
    }

    public function interfaces(string $routerID): JsonResponse
    {
        return $this->driverResult($routerID, 'interfaces', [], 'interfaces');
    }

    public function logs(Request $request, string $routerID): JsonResponse
    {
        $limit = max(1, min(1000, (int) $request->query('limit', 50)));

        return $this->driverResult($routerID, 'logs', [$limit], 'logs');
    }

    private function driverResult(string $id, string $method, array $args, string $key, ?string $countKey = null): JsonResponse
    {
        $session = null;
        try {
            $session = $this->session($id);
            if (! $session) {
                return ApiResponse::fail('Router not found', 404);
            }
            $rows = $session->driver->{$method}($session, ...$args);
            $payload = [$key => $rows];
            if ($countKey) {
                $payload[$countKey] = is_countable($rows) ? count($rows) : 0;
            }

            return ApiResponse::ok($payload);
        } catch (CapabilityException $e) {
            return ApiResponse::fail($e->getMessage(), 501, [$key => [], ...($countKey ? [$countKey => 0] : [])]);
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage());
        } finally {
            if ($session) {
                $session->driver->disconnect($session);
            }
        }
    }

    public function activeUsers(string $routerID): JsonResponse
    {
        $row = DB::table('mikrotiks')->where('router_id', $routerID)->first();
        if (! $row) {
            return ApiResponse::fail('Router hakupatikana', 404);
        }
        $users = DB::table('users')
            ->where('last_router_id', $routerID)->where('role', 'customer')
            ->where('usage_until', '>', now())->get()->all();
        $paid = collect($users)->filter(fn ($u) => (float) ($u->moneyspent ?? 0) > 0)->count();

        return ApiResponse::ok([
            'router' => $this->stripSecrets($row),
            'activeUsers' => $users,
            'stats' => [
                'totalUsers' => count($users),
                'paidUsers' => $paid,
                'adsUsers' => count($users) - $paid,
                'totalRevenue' => round((float) collect($users)->sum('moneyspent'), 2),
            ],
        ]);
    }

    public function details(string $routerID): JsonResponse
    {
        $row = DB::table('mikrotiks')->where('router_id', $routerID)->first();
        if (! $row) {
            return ApiResponse::fail('Router hakupatikana', 404);
        }

        return ApiResponse::ok(['router' => $this->stripSecrets($row)]);
    }

    public function reboot(string $routerID): JsonResponse
    {
        $session = null;
        try {
            $session = $this->session($routerID);
            if (! $session) {
                return ApiResponse::fail('Router not found', 404);
            }
            $ok = $session->driver->reboot($session);

            return ApiResponse::json(['success' => $ok, 'message' => $ok ? 'Router imeanzishwa upya' : 'Imeshindikana kuanzisha upya router']);
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

    public function connect(Request $request, string $routerID): JsonResponse
    {
        try {
            $result = (new ProvisioningService())->connectRouter($routerID, [
                'ssid' => $request->input('ssid'),
            ]);

            return ApiResponse::ok([
                'message' => 'Router imeunganishwa — itapakua config kwenye poll ijayo',
                'router' => $this->stripSecrets($result['router'] ?? null),
                'wireguardIp' => $result['wireguardIp'] ?? null,
                'status' => $result['status'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage());
        }
    }

    /** Test Omada login/sites/devices without exposing credentials. */
    public function omadaTest(string $routerID): JsonResponse
    {
        $row = $this->routerRow($routerID);
        if (! $row) {
            return ApiResponse::fail('Router not found or not active', 404);
        }
        if ($this->manager()->driverFor($row)->name() !== 'omada') {
            return ApiResponse::fail('Router hii haiko kwenye Omada mode', 400);
        }
        try {
            $session = $this->manager()->connect($row);
            $client = $session->connection;
            $client->authenticate();
            $sites = $client->sites();
            $devices = $client->devices();

            return ApiResponse::ok([
                'base_url' => $client->baseUrl(),
                'mode' => $client->mode(),
                'site' => $client->siteId() ?: '(auto)',
                'sites' => count($sites),
                'devices' => count($devices),
                'error' => $client->lastError(),
            ]);
        } catch (\Throwable $e) {
            return ApiResponse::fail('Omada connection failed: '.$e->getMessage(), 502);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'router_id' => 'required|string',
            'router_name' => 'required|string',
            'host' => 'required|string',
            'user' => 'nullable|string',
            'password' => 'nullable|string',
            'driver' => 'sometimes|in:mikrotik,omada',
            'omada_mode' => 'sometimes|in:onprem,cloud',
            'omada_controller_url' => 'sometimes|nullable|string',
            'omada_site_id' => 'sometimes|nullable|string',
            'omada_client_id' => 'sometimes|nullable|string',
            'omada_client_secret' => 'sometimes|nullable|string',
            'omada_username' => 'sometimes|nullable|string',
            'omada_password' => 'sometimes|nullable|string',
        ]);
        $driver = strtolower((string) ($data['driver'] ?? env('ROUTER_DRIVER', 'mikrotik')));
        if ($driver !== 'omada' && (empty($data['user']) || empty($data['password']))) {
            return ApiResponse::fail('MikroTik user na password vinahitajika', 422);
        }
        $insert = [
            'router_id' => $data['router_id'], 'router_name' => $data['router_name'],
            'host' => $data['host'], 'user' => $data['user'] ?? null,
            'password' => ! empty($data['password']) ? Encryption::encrypt($data['password']) : null,
            'driver' => $driver,
            'port' => (int) ($request->input('port') ?: ($driver === 'omada' ? 8043 : 8728)),
            'location_id' => $request->input('location_id'),
            'ssid' => $request->input('ssid'), 'model' => $request->input('model'),
            'serial_number' => $request->input('serial_number'),
            'status' => 'offline', 'created_at' => now(), 'updated_at' => now(),
        ];
        foreach (['omada_mode', 'omada_controller_url', 'omada_site_id', 'omada_client_id', 'omada_username'] as $key) {
            if ($request->filled($key)) {
                $insert[$key] = $request->input($key);
            }
        }
        foreach (['omada_client_secret', 'omada_password'] as $secret) {
            if ($request->filled($secret)) {
                $insert[$secret] = Encryption::encrypt((string) $request->input($secret));
            }
        }
        try {
            DB::table('mikrotiks')->insert($insert);

            return ApiResponse::success([], 'Router imeongezwa kikamilifu');
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage());
        }
    }

    public function update(Request $request, string $id): JsonResponse
    {
        if (! DB::table('mikrotiks')->where('id', $id)->exists()) {
            return ApiResponse::fail('Router hakupatikana', 404);
        }
        $data = [
            'router_name' => $request->input('router_name'),
            'host' => $request->input('host'),
            'user' => $request->input('user'),
            'port' => $request->input('port'),
            'ssid' => $request->input('ssid'),
            'status' => $request->input('status'),
            'driver' => $request->input('driver'),
            'omada_mode' => $request->input('omada_mode'),
            'omada_controller_url' => $request->input('omada_controller_url'),
            'omada_site_id' => $request->input('omada_site_id'),
            'omada_client_id' => $request->input('omada_client_id'),
            'omada_username' => $request->input('omada_username'),
            'updated_at' => now(),
        ];
        if ($request->filled('password')) {
            $data['password'] = Encryption::encrypt((string) $request->input('password'));
        }
        if ($request->filled('omada_password')) {
            $data['omada_password'] = Encryption::encrypt((string) $request->input('omada_password'));
        }
        if ($request->filled('omada_client_secret')) {
            $data['omada_client_secret'] = Encryption::encrypt((string) $request->input('omada_client_secret'));
        }
        try {
            DB::table('mikrotiks')->where('id', $id)->update(array_filter($data, fn ($v) => $v !== null, ARRAY_FILTER_USE_BOTH));

            return ApiResponse::success([], 'Router imebadilishwa kikamilifu');
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage());
        }
    }

    public function destroy(string $id): JsonResponse
    {
        $deleted = DB::table('mikrotiks')->where('id', $id)->orWhere('router_id', $id)->delete();
        if (! $deleted) {
            return ApiResponse::fail('Router hakupatikana', 404);
        }

        return ApiResponse::success([], 'Router imefutwa kikamilifu');
    }

    // ---------------- WireGuard endpoints ----------------

    public function wireGuardPeers(): JsonResponse
    {
        return ApiResponse::json((new WireGuardService())->getWireGuardPeers());
    }

    public function wireGuardServerConfig(): JsonResponse
    {
        $wg = new WireGuardService();
        $peers = $wg->getWireGuardPeers();

        return ApiResponse::ok([
            'config' => $wg->generateServerConfig($peers['peers'] ?? []),
            'serverInfo' => [
                'publicIp' => $wg->serverPublicIp, 'port' => $wg->listenPort, 'network' => $wg->networkCidr,
            ],
        ]);
    }

    public function generateWireGuard(string $routerID): JsonResponse
    {
        $row = $this->routerRow($routerID);
        if (! $row) {
            return ApiResponse::fail('Router not found', 404);
        }
        $wg = new WireGuardService();
        $config = $wg->generateMikroTikConfig([
            'routerName' => $row['router_name'] ?? $routerID,
            'assignedIp' => $row['wireguard_ip'] ?? '',
            'privateKey' => $row['wireguard_private_key'] ?? '',
            'serverPublicKey' => $wg->ensureServerKeys()['publicKey'],
            'serverEndpoint' => $wg->serverPublicIp,
        ]);
        // Never leak the router's private key / PSK through an admin response.
        if (! empty($row['wireguard_private_key'])) {
            $config = str_replace((string) $row['wireguard_private_key'], '<redacted>', $config);
        }
        if (! empty($row['wireguard_preshared_key'])) {
            $config = str_replace((string) $row['wireguard_preshared_key'], '<redacted>', $config);
        }

        return ApiResponse::ok([
            'success' => true,
            'config' => $config,
            'router' => $this->stripSecrets($row),
            'note' => 'Private key redacted. The full config is delivered to the router by zero-touch provisioning.',
        ]);
    }

    public function vpsGuide(): JsonResponse
    {
        return ApiResponse::ok(['guide' => (new WireGuardService())->getVpsSetupGuide()]);
    }
}
