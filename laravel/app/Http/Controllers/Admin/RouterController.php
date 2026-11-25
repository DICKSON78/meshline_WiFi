<?php

namespace App\Http\Controllers\Admin;

use App\Support\ApiResponse;
use App\Support\Encryption;
use App\Services\Routers\RouterSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Admin router management. Every device call goes through RouterManager. */
class RouterController extends BaseController
{
    public function index(): JsonResponse
    {
        try {
            $routers = DB::select(
                "SELECT m.*,
                        COALESCE(l.name, m.location_name) as location_name,
                        COALESCE(l.city, m.location_city) as city,
                        COALESCE(l.region, m.location_region) as region,
                        b.name as branch_name,
                        (SELECT COUNT(DISTINCT u.id) FROM users u
                           LEFT JOIN hotspot_sessions hs ON hs.user_id = u.id AND (hs.router_id = m.router_id OR hs.router_id = m.id)
                           WHERE u.role = 'customer' AND (
                             u.last_router_id = m.router_id OR u.last_router_id = m.id OR
                             hs.router_id = m.router_id OR hs.router_id = m.id)) as customer_count,
                        (SELECT COUNT(DISTINCT u.id) FROM users u
                           LEFT JOIN hotspot_sessions hs ON hs.user_id = u.id AND (hs.router_id = m.router_id OR hs.router_id = m.id)
                           WHERE u.role = 'customer' AND (
                             u.last_router_id = m.router_id OR u.last_router_id = m.id OR
                             hs.router_id = m.router_id OR hs.router_id = m.id)
                             AND u.usage_until IS NOT NULL AND u.usage_until > NOW()) as active_bundle_users
                 FROM mikrotiks m
                 LEFT JOIN locations l ON m.location_id = l.id
                 LEFT JOIN branches b ON b.id = l.branch_id
                 ORDER BY m.id DESC"
            );
            $safe = $this->stripSecrets($routers);

            return ApiResponse::json([
                'success' => true,
                'routers' => $safe,
                'data' => $safe,
                'count' => count($safe),
            ]);
        } catch (Throwable $e) {
            return $this->plainFail('Imeshindikana kupata routers');
        }
    }

    /** DB-only health (mikrotikService.getRouterHealth never contacts the device). */
    public function health(Request $request): JsonResponse
    {
        $routerID = $request->query('routerID', 'router-default');
        $row = null;
        try {
            $query = DB::table('mikrotiks')->select('status', 'last_seen');
            if (ctype_digit((string) $routerID)) {
                $query->where('id', (int) $routerID);
            } else {
                $query->where('router_id', (string) $routerID);
            }
            $row = $query->first();
        } catch (Throwable $e) {
            // Columns may be absent on older installations.
        }
        $isOnline = $row && $row->status === 'online';
        $health = [
            'routerID' => $routerID,
            'status' => $isOnline ? 'online' : 'offline',
            'connectedUsers' => 0,
            'cpu' => 0,
            'memory' => 0,
            'uptime' => $isOnline ? 'Online' : '0s',
            'version' => 'unknown',
            'identity' => 'unknown',
            'lastSeen' => $row->last_seen ?? now()->toIso8601String(),
        ];

        return ApiResponse::json(['success' => true, 'data' => $health]);
    }

    public function statusAll(): JsonResponse
    {
        try {
            $routers = DB::select(
                "SELECT m.router_id, m.router_name, m.status, m.setup_status, m.wireguard_configured,
                        (SELECT COUNT(*) FROM users u WHERE u.last_router_id = m.router_id AND u.role = 'customer') AS total_users,
                        (SELECT COUNT(*) FROM users u WHERE u.last_router_id = m.router_id
                          AND u.usage_until > NOW() AND u.role = 'customer') AS active_users
                 FROM mikrotiks m"
            );
            $peers = $this->wireguardPeerRows();
            $wgMap = [];
            foreach ($peers as $peer) {
                $wgMap[(string) ($peer->router_id ?? '')] = $peer;
            }
            $statuses = array_map(static function ($router) use ($wgMap): array {
                $tunnelOnline = isset($wgMap[(string) $router->router_id])
                    && ($wgMap[(string) $router->router_id]->status ?? null) === 'online';
                $apiOnline = ($router->status ?? null) === 'online';
                $isOnline = $apiOnline || $tunnelOnline;

                return [
                    'routerId' => $router->router_id,
                    'routerName' => $router->router_name,
                    'isOnline' => $isOnline,
                    'apiReachable' => $apiOnline,
                    'totalUsers' => (int) ($router->total_users ?? 0),
                    'activeUsers' => $apiOnline ? 0 : null,
                    'cpu' => $apiOnline ? 0 : null,
                    'memory' => $apiOnline ? 0 : null,
                    'uptime' => $apiOnline ? 'Online' : ($tunnelOnline ? 'WireGuard connected' : 'N/A'),
                ];
            }, $routers);

            return ApiResponse::json(['success' => true, 'routers' => $statuses]);
        } catch (Throwable $e) {
            return $this->plainFail('Imeshindikana kupata status');
        }
    }

    public function show(int|string $id): JsonResponse
    {
        $row = $this->resolveRouter($id, false);
        if (! $row) {
            return $this->plainFail('Router hakupatikana', 404);
        }

        return ApiResponse::json(['success' => true, 'router' => $this->stripSecrets($row)]);
    }

    public function liveStatus(int|string $routerID): JsonResponse
    {
        $session = $this->connectRouter($routerID);
        if (! $session) {
            return $this->routerFailure('Imeshindikana kupata status');
        }
        $identityResult = $this->callRouter($session, 'identity');
        if (! $identityResult['success']) {
            return $this->plainFail($identityResult['error']);
        }
        $identity = is_object($identityResult['data'])
            ? (array) $identityResult['data']
            : (array) ($identityResult['data'] ?? []);
        $sessions = $this->callRouter($session, 'activeSessions');
        $interfaces = $this->callRouter($session, 'interfaces');
        $sessionRows = $sessions['success'] ? $this->normalizeList($sessions['data']) : [];
        $interfaceRows = $interfaces['success'] ? $this->normalizeList($interfaces['data']) : [];

        $status = [
            'routerID' => $routerID,
            'identity' => $identity['name'] ?? 'Unknown',
            'isOnline' => true,
            'uptime' => $identity['uptime'] ?? '0s',
            'version' => $identity['version'] ?? $identity['firmware'] ?? 'Unknown',
            'cpu' => (int) ($identity['cpu_load'] ?? 0),
            'memory' => (int) ($identity['memory_usage'] ?? 0),
            'activeUsers' => count($sessionRows),
            'interfaces' => count(array_filter($interfaceRows, static fn ($row) => empty($row['disabled']))),
            'totalInterfaces' => count($interfaceRows),
            'platform' => $identity['platform'] ?? 'Unknown',
            'boardName' => $identity['board_name'] ?? $identity['model'] ?? 'Unknown',
            'lastChecked' => now()->toIso8601String(),
        ];

        return ApiResponse::json(['success' => true, 'status' => $status]);
    }

    /** /router-sessions/:id and /routers/:id/sessions — hotspot user list. */
    public function sessions(int|string $routerID): JsonResponse
    {
        $session = $this->connectRouter($routerID);
        if (! $session) {
            return $this->routerFailure('Imeshindikana kupata sessions');
        }
        $result = $this->callRouter($session, 'hotspotUsers');
        if (! $result['success']) {
            return $this->plainFail($result['error']);
        }
        $users = $this->normalizeList($result['data']);

        return ApiResponse::json(['success' => true, 'data' => $users, 'count' => count($users)]);
    }

    public function hotspotSessions(int|string $routerID): JsonResponse
    {
        $session = $this->connectRouter($routerID);
        if (! $session) {
            return $this->routerFailure('Imeshindikana kupata sessions');
        }
        $result = $this->callRouter($session, 'activeSessions');
        if (! $result['success']) {
            return $this->plainFail($result['error']);
        }
        $sessions = $this->normalizeList($result['data']);

        return ApiResponse::json(['success' => true, 'sessions' => $sessions, 'count' => count($sessions)]);
    }

    public function hotspotUsers(int|string $routerID): JsonResponse
    {
        $session = $this->connectRouter($routerID);
        if (! $session) {
            return $this->routerFailure('Imeshindikana kupata users');
        }
        $result = $this->callRouter($session, 'hotspotUsers');
        if (! $result['success']) {
            return $this->plainFail($result['error']);
        }

        return ApiResponse::json(['success' => true, 'users' => $this->normalizeList($result['data'])]);
    }

    public function kick(int|string $routerID, Request $request): JsonResponse
    {
        $sessionId = $request->input('sessionId');
        if (! $sessionId) {
            return $this->plainFail('Session ID inahitajika');
        }
        $session = $this->connectRouter($routerID);
        if (! $session) {
            return $this->routerFailure('Imeshindikana kumfukuza mtumiaji');
        }
        $result = $this->callRouter($session, 'kickSession', [(string) $sessionId]);
        if (! $result['success']) {
            return ApiResponse::json(['success' => false, 'error' => $result['error']]);
        }

        return ApiResponse::json(['success' => true, 'message' => 'User disconnected']);
    }

    public function bindings(int|string $routerID): JsonResponse
    {
        $session = $this->connectRouter($routerID);
        if (! $session) {
            return $this->routerFailure('Imeshindikana kupata bindings');
        }
        $result = $this->callRouter($session, 'rawCommand', [], ['/ip/hotspot/ip-binding/print']);
        if (! $result['success']) {
            return ApiResponse::json([
                'success' => false,
                'error' => $result['error'],
                'bindings' => [],
            ]);
        }
        $bindings = array_values(array_filter(
            $this->normalizeList($result['data']),
            static fn ($row) => ! isset($row['!empty'])
        ));

        return ApiResponse::json(['success' => true, 'bindings' => $bindings]);
    }

    public function interfaces(int|string $routerID): JsonResponse
    {
        $session = $this->connectRouter($routerID);
        if (! $session) {
            return $this->routerFailure('Imeshindikana kupata interfaces');
        }
        $result = $this->callRouter($session, 'interfaces');
        if (! $result['success']) {
            return $this->plainFail($result['error']);
        }

        return ApiResponse::json(['success' => true, 'interfaces' => $this->normalizeList($result['data'])]);
    }

    public function logs(int|string $routerID, Request $request): JsonResponse
    {
        $limit = $this->clampInt($request->query('limit'), 50, 1, 10000);
        $session = $this->connectRouter($routerID);
        if (! $session) {
            return $this->routerFailure('Imeshindikana kupata logs');
        }
        $result = $this->callRouter($session, 'logs', [$limit]);
        if (! $result['success']) {
            return $this->plainFail($result['error']);
        }
        $logs = $this->normalizeList($result['data']);

        return ApiResponse::json(['success' => true, 'logs' => array_slice($logs, -$limit)]);
    }

    public function details(int|string $routerID): JsonResponse
    {
        try {
            $router = DB::selectOne(
                "SELECT m.*,
                        l.name as location_name, l.city, l.region, l.address as location_address,
                        l.latitude, l.longitude, l.location_type,
                        b.name as branch_name,
                        fo.name as owner_name, fo.phone_number as owner_phone, fo.email as owner_email,
                        (SELECT COUNT(DISTINCT u.id) FROM users u LEFT JOIN hotspot_sessions hs ON hs.user_id = u.id AND (hs.router_id = m.router_id OR hs.router_id = m.id) WHERE u.role = 'customer' AND (u.last_router_id = m.router_id OR u.last_router_id = m.id OR hs.router_id = m.router_id OR hs.router_id = m.id) AND u.usage_until IS NOT NULL AND u.usage_until > NOW()) as active_users_count,
                        (SELECT COUNT(*) FROM users WHERE last_router_id = m.router_id AND role = 'customer') as total_users_count,
                        (SELECT COALESCE(SUM(moneyspent), 0) FROM users WHERE last_router_id = m.router_id AND role = 'customer') as total_revenue
                 FROM mikrotiks m
                 LEFT JOIN locations l ON m.location_id = l.id
                 LEFT JOIN branches b ON b.id = l.branch_id
                 LEFT JOIN users fo ON l.franchise_owner_id = fo.id
                 WHERE m.router_id = ?",
                [$routerID]
            );
            if (! $router) {
                return $this->plainFail('Router hakupatikana', 404);
            }

            return ApiResponse::json(['success' => true, 'router' => $this->stripSecrets($router)]);
        } catch (Throwable $e) {
            return $this->plainFail('Imeshindikana kupata taarifa');
        }
    }

    public function activeUsers(int|string $routerID): JsonResponse
    {
        try {
            $router = DB::selectOne(
                'SELECT m.*, l.name as location_name, l.city, l.region,
                        fo.name as owner_name, fo.phone_number as owner_phone, fo.email as owner_email
                 FROM mikrotiks m
                 LEFT JOIN locations l ON m.location_id = l.id
                 LEFT JOIN users fo ON l.franchise_owner_id = fo.id
                 WHERE m.router_id = ?',
                [$routerID]
            );
            if (! $router) {
                return $this->plainFail('Router hakupatikana', 404);
            }
            $activeUsers = DB::select(
                "SELECT u.id, u.name, u.phone_number, u.mac_address, u.package, u.usage_start, u.usage_until,
                        u.free_bytes / (1024*1024) as free_mb, u.moneyspent,
                        TIMESTAMPDIFF(SECOND, NOW(), u.usage_until) as seconds_remaining,
                        CASE WHEN u.package IS NULL OR u.package = '' THEN 'ads'
                             WHEN u.moneyspent > 0 THEN 'paid' ELSE 'ads' END as subscription_type,
                        CASE WHEN u.usage_until <= NOW() THEN 0 WHEN u.usage_start IS NULL THEN 0
                             ELSE ROUND((TIMESTAMPDIFF(SECOND, u.usage_start, NOW()) /
                                  TIMESTAMPDIFF(SECOND, u.usage_start, u.usage_until)) * 100)
                        END as usage_progress_percent
                 FROM users u
                 WHERE u.last_router_id = ? AND u.usage_until > NOW() AND u.role = 'customer'
                 ORDER BY u.usage_until ASC",
                [$routerID]
            );
            $paid = 0;
            $ads = 0;
            $revenue = 0.0;
            foreach ($activeUsers as $user) {
                if (($user->subscription_type ?? '') === 'paid') {
                    $paid++;
                } else {
                    $ads++;
                }
                $revenue += $this->num($user->moneyspent ?? 0);
            }

            return ApiResponse::json([
                'success' => true,
                'router' => $this->stripSecrets($router),
                'activeUsers' => $activeUsers,
                'stats' => [
                    'totalUsers' => count($activeUsers),
                    'paidUsers' => $paid,
                    'adsUsers' => $ads,
                    'totalRevenue' => $revenue,
                ],
            ]);
        } catch (Throwable $e) {
            return $this->plainFail('Imeshindikana kupata watumiaji');
        }
    }

    public function reboot(int|string $routerID): JsonResponse
    {
        $session = $this->connectRouter($routerID);
        if (! $session) {
            return $this->plainFail('Imeshindikana kuanzisha upya router');
        }
        $result = $this->callRouter($session, 'reboot');
        $ok = $result['success'] && ($result['data'] !== false);

        return ApiResponse::json([
            'success' => $ok,
            'message' => $ok ? 'Router imeanzishwa upya' : 'Imeshindikana kuanzisha upya router',
        ]);
    }

    /** Zero-touch approval is owned by another agent; degrade if unavailable. */
    public function connect(int|string $routerID, Request $request): JsonResponse
    {
        $service = null;
        foreach ([
            'App\\Services\\Routers\\ProvisioningService',
            'App\\Services\\Routers\\RouterProvisionService',
            'App\\Services\\RouterProvision',
        ] as $class) {
            if (class_exists($class)) {
                $service = $class;
                break;
            }
        }
        if ($service === null) {
            return $this->plainFail('Imeshindikana kuunganisha router');
        }
        try {
            $object = app($service);
            $result = method_exists($object, 'connectRouter')
                ? $object->connectRouter($routerID, ['ssid' => $request->input('ssid')])
                : null;
            if (! is_array($result)) {
                return $this->plainFail('Imeshindikana kuunganisha router');
            }

            return ApiResponse::json([
                'success' => true,
                'message' => 'Router imeunganishwa — itapakua config kwenye poll ijayo',
                'router' => $this->stripSecrets($result['router'] ?? null),
                'wireguardIp' => $result['wireguardIp'] ?? null,
                'status' => $result['status'] ?? null,
            ]);
        } catch (Throwable $e) {
            return $this->plainFail($e->getMessage() ?: 'Imeshindikana kuunganisha router');
        }
    }

    public function store(Request $request): JsonResponse
    {
        $required = ['router_id', 'router_name', 'host', 'user', 'password'];
        foreach ($required as $field) {
            if (! $request->input($field)) {
                return $this->plainFail('Taarifa zote za msingi zinahitajika');
            }
        }
        try {
            DB::table('mikrotiks')->insert([
                'router_id' => $request->input('router_id'),
                'router_name' => $request->input('router_name'),
                'host' => $request->input('host'),
                'user' => $request->input('user'),
                'password' => Encryption::encrypt((string) $request->input('password')),
                'port' => $request->input('port', 8728),
                'location_id' => $request->input('location_id'),
                'ssid' => $request->input('ssid'),
                'model' => $request->input('model'),
                'serial_number' => $request->input('serial_number'),
                'status' => 'offline',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ApiResponse::json(['success' => true, 'message' => 'Router imeongezwa kikamilifu']);
        } catch (Throwable $e) {
            return $this->plainFail('Imeshindikana kuongeza router');
        }
    }

    public function update(Request $request, int|string $id): JsonResponse
    {
        if (! $this->resolveRouter($id, false)) {
            return $this->plainFail('Router hakupatikana', 404);
        }
        $values = [];
        foreach (['router_name', 'host', 'user', 'port', 'ssid', 'status'] as $field) {
            if ($request->exists($field)) {
                $values[$field] = $request->input($field);
            }
        }
        if ($request->input('password')) {
            $values['password'] = Encryption::encrypt((string) $request->input('password'));
        }
        $values['updated_at'] = now();
        try {
            DB::table('mikrotiks')->where('id', $id)->update($values);

            return ApiResponse::json(['success' => true, 'message' => 'Router imebadilishwa kikamilifu']);
        } catch (Throwable $e) {
            return $this->plainFail('Imeshindikana kubadilisha router');
        }
    }

    public function destroy(int|string $id): JsonResponse
    {
        try {
            $deleted = DB::table('mikrotiks')->where('id', $id)->orWhere('router_id', (string) $id)->delete();
            if (! $deleted) {
                return $this->plainFail('Router hakupatikana', 404);
            }

            return ApiResponse::json(['success' => true, 'message' => 'Router imefutwa kikamilifu']);
        } catch (Throwable $e) {
            return $this->plainFail('Imeshindikana kufuta router');
        }
    }

    // ─────────────────────────── WireGuard ───────────────────────────

    private function wireguardPeerRows(): array
    {
        try {
            return DB::select(
                'SELECT router_id, router_name, wireguard_ip, wireguard_public_key, status, last_seen,
                        wireguard_configured, setup_status
                 FROM mikrotiks WHERE wireguard_configured = 1 OR wireguard_ip IS NOT NULL
                 ORDER BY wireguard_ip'
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    private function runtimeWireguardStatus(): ?array
    {
        $file = env('WG_STATUS_FILE');
        if (! $file) {
            $file = is_dir('/var/run/hotbando') ? '/var/run/hotbando/wg-status-result' : '/etc/wireguard/.wg-status-result';
        }
        try {
            if (is_file($file)) {
                $decoded = json_decode((string) file_get_contents($file), true);

                return is_array($decoded) ? $decoded : null;
            }
        } catch (Throwable $e) {
            // The host status file is optional.
        }

        return null;
    }

    public function wireguardPeers(): JsonResponse
    {
        try {
            $peers = $this->wireguardPeerRows();
            $runtime = $this->runtimeWireguardStatus();
            $configured = array_values(array_filter(
                $peers,
                static fn ($peer) => (int) ($peer->wireguard_configured ?? 0) === 1 && ($peer->setup_status ?? null) === 'configured'
            ));
            $singleActive = $runtime && ! empty($runtime['running'])
                && (int) ($runtime['activePeers'] ?? 0) === 1 && count($configured) === 1
                ? $configured[0]->router_id
                : null;
            $mapped = array_map(static function ($peer) use ($runtime, $singleActive): array {
                $key = (string) ($peer->wireguard_public_key ?? '');
                $handshake = (int) ($runtime['peerHandshakes'][$key] ?? 0);
                $fresh = $handshake > 0 && (time() - $handshake) <= 180;
                $hasHandshakes = isset($runtime['peerHandshakes']);
                $activeByCount = (string) ($peer->router_id ?? '') === (string) $singleActive;
                $online = $hasHandshakes
                    ? ! empty($runtime['running']) && $fresh
                    : $activeByCount;
                $observed = (int) ($runtime['updatedAt'] ?? 0);
                $lastSeen = $handshake > 0
                    ? gmdate('c', $handshake)
                    : ($activeByCount && $observed ? gmdate('c', $observed) : $peer->last_seen);

                return [
                    'routerId' => $peer->router_id,
                    'name' => $peer->router_name,
                    'wgIp' => $peer->wireguard_ip,
                    'publicKey' => $peer->wireguard_public_key,
                    'status' => $online ? 'online' : ($hasHandshakes ? 'offline' : $peer->status),
                    'lastSeen' => $lastSeen,
                ];
            }, $peers);

            return ApiResponse::json(['success' => true, 'peers' => $mapped]);
        } catch (Throwable $e) {
            return ApiResponse::json(['success' => true, 'peers' => []]);
        }
    }

    public function wireguardServerConfig(): JsonResponse
    {
        $peersResponse = $this->wireguardPeers();
        $peers = $peersResponse->getData(true)['peers'] ?? [];
        $serverIp = env('WG_SERVER_IP', '10.7.0.1');
        $listenPort = (int) env('WG_PORT', 51820);
        $config = "# HotBando WireGuard Server\n[Interface]\nAddress = {$serverIp}/24\nListenPort = {$listenPort}\nPrivateKey = <SERVER_PRIVATE_KEY>\n";
        foreach ($peers as $peer) {
            $allowed = $peer['wgIp'] ?? '0.0.0.0';
            $config .= "\n[Peer]\nPublicKey = ".($peer['publicKey'] ?? '')."\nAllowedIPs = {$allowed}/32\n";
        }

        return ApiResponse::json([
            'success' => true,
            'config' => $config,
            'serverInfo' => [
                'publicIp' => env('SERVER_PUBLIC_HOST') ?: (env('SERVER_PUBLIC_IP') ?: 'hotbandowifi.online'),
                'port' => $listenPort,
                'network' => env('WG_NETWORK', '10.7.0.0/16'),
            ],
        ]);
    }

    public function wireguardGenerate(int|string $routerID): JsonResponse
    {
        $row = $this->resolveRouter($routerID, false);
        if (! $row) {
            return $this->plainFail('Router not found', 404);
        }

        $serverPublicKey = trim((string) (env('WG_SERVER_PUBLIC_KEY') ?: ''));
        if ($serverPublicKey === '' && is_file('/etc/wireguard/hotbando-keys/server.key.pub')) {
            $serverPublicKey = trim((string) file_get_contents('/etc/wireguard/hotbando-keys/server.key.pub'));
        }
        if ($serverPublicKey === '') {
            return $this->plainFail('WG_SERVER_PUBLIC_KEY is not configured and no host server public key is mounted. Refusing to generate a new server keypair inside the app container.');
        }

        try {
            $result = DB::transaction(function () use ($row, $routerID, $serverPublicKey): array {
                $locked = DB::table('mikrotiks')->where('id', $row['id'])->lockForUpdate()->first();
                $used = [];
                foreach (DB::table('mikrotiks')->pluck('wireguard_ip') as $ip) {
                    if ($ip) {
                        $used[(string) $ip] = true;
                    }
                }
                $runtime = $this->runtimeWireguardStatus();
                foreach ($runtime['allowedIps'] ?? [] as $ip) {
                    if (preg_match('/^\d{1,3}(?:\.\d{1,3}){3}$/', (string) $ip)) {
                        $used[(string) $ip] = true;
                    }
                }
                [$base, $prefix] = explode('/', (string) env('WG_NETWORK', '10.7.0.0/16')) + [1 => '16'];
                $serverIp = env('WG_SERVER_IP', '10.7.0.1');
                $parts = explode('.', (string) $serverIp);
                $prefix3 = $parts[0].'.'.$parts[1];
                $assignedIp = null;
                for ($third = 0; $third <= 255 && $assignedIp === null; $third++) {
                    for ($fourth = $third === (int) ($parts[2] ?? 0) ? ((int) ($parts[3] ?? 1) + 1) : 1; $fourth <= 254; $fourth++) {
                        $candidate = "{$prefix3}.{$third}.{$fourth}";
                        if (! isset($used[$candidate])) {
                            $assignedIp = $candidate;
                            break;
                        }
                    }
                }
                if (! $assignedIp) {
                    throw new \RuntimeException('No available IPs in WireGuard network');
                }

                $keyPair = sodium_crypto_box_keypair();
                $privateKey = sodium_crypto_box_secretkey($keyPair);
                $publicKey = sodium_crypto_box_publickey($keyPair);
                $presharedKey = random_bytes(32);
                $privateB64 = base64_encode($privateKey);
                $publicB64 = base64_encode($publicKey);
                $presharedB64 = base64_encode($presharedKey);

                DB::table('mikrotiks')->where('id', $row['id'])->update([
                    'wireguard_ip' => $assignedIp,
                    'wireguard_public_key' => $publicB64,
                    'wireguard_private_key' => Encryption::encrypt($privateB64),
                    'wireguard_preshared_key' => Encryption::encrypt($presharedB64),
                    'wireguard_configured' => 1,
                    'setup_status' => 'configuring',
                    'updated_at' => now(),
                ]);

                $routerName = $locked->router_name ?? $row['router_name'] ?? (string) $routerID;
                $listenPort = (int) env('WG_PORT', 51820);
                $endpoint = env('SERVER_PUBLIC_HOST') ?: (env('SERVER_PUBLIC_IP') ?: 'hotbandowifi.online');
                $config = <<<RSC
# WireGuard Configuration — {$routerName}
# Generated: {$this->nowIso()}

# 1. Create WireGuard interface
/interface wireguard add name=wg-hotbando listen-port=13231 private-key="{$privateB64}"

# 2. Add server as peer
/interface wireguard peers add \\
    interface=wg-hotbando \\
    public-key="{$serverPublicKey}" \\
    endpoint-address={$endpoint} \\
    endpoint-port={$listenPort} \\
    allowed-address={$serverIp}/32 \\
    persistent-keepalive=25s

# 3. Assign IP
/ip address add address={$assignedIp}/32 interface=wg-hotbando

# 4. Route to server network
/ip route add dst-address={$serverIp}/32 gateway=wg-hotbando

# 5. Firewall: allow WG traffic
/ip firewall filter add chain=input protocol=udp dst-port=13231 action=accept comment="WireGuard Input"
/ip firewall filter add chain=forward in-interface=wg-hotbando action=accept comment="WireGuard Fwd In"
/ip firewall filter add chain=forward out-interface=wg-hotbando action=accept comment="WireGuard Fwd Out"

# 6. Restrict API access to WG network
/ip service set api address={$base}/{$prefix}
RSC;

                return [
                    'success' => true,
                    'config' => $config,
                    'routerId' => $routerID,
                    'wireguardIp' => $assignedIp,
                    'publicKey' => $publicB64,
                    'message' => 'WireGuard config imetengenezwa',
                ];
            });

            return ApiResponse::json($result);
        } catch (Throwable $e) {
            return ApiResponse::json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    private function nowIso(): string
    {
        return now()->toIso8601String();
    }

    public function wireguardVpsGuide(): JsonResponse
    {
        $host = env('SERVER_PUBLIC_HOST') ?: (env('SERVER_PUBLIC_IP') ?: 'hotbandowifi.online');
        $port = (int) env('WG_PORT', 51820);
        $network = env('WG_NETWORK', '10.7.0.0/16');

        return ApiResponse::json([
            'success' => true,
            'guide' => "# This server IS the WireGuard hub. No external VPS needed.\n"
                ."# WireGuard is managed automatically by the system.\n"
                ."# Server public host: {$host}\n# WireGuard port: {$port}\n# Network: {$network}",
        ]);
    }

    /** Normalize driver output (arrays, collections or {rows:...} wrappers). */
    private function normalizeList(mixed $data): array
    {
        if ($data === null) {
            return [];
        }
        if (is_object($data) && method_exists($data, 'toArray')) {
            $data = $data->toArray();
        }
        if ($data instanceof \Illuminate\Support\Collection) {
            return $data->all();
        }
        if (is_object($data)) {
            $data = (array) $data;
        }
        if (! is_array($data)) {
            return [];
        }
        foreach (['sessions', 'users', 'interfaces', 'logs', 'bindings', 'rows', 'data'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return array_values($data[$key]);
            }
        }
        if (array_is_list($data)) {
            return array_map(static fn ($row) => is_object($row) ? (array) $row : $row, $data);
        }

        return [$this->stripSecrets($data)];
    }
}
