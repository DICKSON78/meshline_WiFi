<?php

namespace App\Http\Controllers\Router;

use App\Http\Controllers\Controller;
use App\Services\Routers\MikrotikDetector;
use App\Services\Routers\MikrotikDriver;
use App\Services\Routers\ProvisioningService;
use App\Services\Routers\RouterOsClient;
use App\Services\Routers\WireGuardService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Port of routes/mikrotikSetup.js: detect/scan/configure (SSE) plus the
 * WireGuard server helpers.
 */
class MikrotikSetupController extends Controller
{
    use RouterResponseTrait;

    public function __construct(
        private readonly MikrotikDetector $detector = new MikrotikDetector(),
        private readonly ProvisioningService $provisioning = new ProvisioningService(),
        private readonly WireGuardService $wireguard = new WireGuardService(),
    ) {}

    public function detect(Request $request): JsonResponse
    {
        $ip = (string) $request->query('ip', '');
        if ($ip === '') {
            return ApiResponse::fail('IP is required');
        }
        $port = (int) $request->query('port', 8728);
        $result = $this->detector->verifyMikrotik($ip, $port,
            (string) $request->query('user', 'admin'), (string) $request->query('password', ''));
        if (! empty($result['authFailed'])) {
            $found = $this->detector->findWorkingCredentials($ip, $port);
            if ($found) {
                $result = array_merge($result, $found, ['credentialsAuto' => [
                    'user' => $found['user'], 'password' => $found['password'],
                ]]);
            }
        }
        if (! empty($result['isMikrotik']) && empty($result['authFailed'])) {
            $existing = DB::table('mikrotiks')->where('host', $ip)
                ->first(['id', 'router_id', 'router_name', 'setup_status']);
            $result['inDatabase'] = $existing !== null;
            $result['existingRouter'] = $existing ? (array) $existing : null;
        }
        if (empty($result['isMikrotik'])) {
            $result = array_merge($result, $this->detectionMessage($ip, $port, (bool) ($result['authFailed'] ?? false)));
        }

        return ApiResponse::ok($result);
    }

    private function detectionMessage(string $ip, int $port, bool $authFailed): array
    {
        if (str_starts_with($ip, '10.7.')) {
            $router = DB::table('mikrotiks')->where('wireguard_ip', $ip)
                ->first(['router_id', 'router_name', 'setup_status']);
            if ($router) {
                return [
                    'message' => "Router \"{$router->router_name}\" is registered ({$router->setup_status}) but its tunnel is DOWN — it has never connected (no WireGuard handshake). Power it on, check its internet, or re-run its bootstrap script.",
                    'tunnelDown' => true,
                    'existingRouter' => ['router_id' => $router->router_id, 'router_name' => $router->router_name],
                ];
            }

            return ['message' => "No registered router owns tunnel IP {$ip}. A router appears here only after it phones home and you press Connect."];
        }
        if ($authFailed) {
            return ['message' => "A MikroTik responded at {$ip} but the credentials were rejected and no default credentials worked. Enter the correct admin password (Manual connect)."];
        }
        $private = str_starts_with($ip, '10.') || str_starts_with($ip, '192.168.') || str_starts_with($ip, '127.');
        if ($private) {
            return [
                'message' => "This server cannot reach {$ip}:{$port} — that address lives on the technician laptop/router LAN, not on the cloud. Open Site Setup, copy the site script, paste it in WebFig Terminal, then wait for PENDING and press Connect.",
                'useSiteSetup' => true,
            ];
        }

        return [
            'message' => "Nothing responding at {$ip}:{$port}. Check power, cable/WiFi and that the router API port is reachable. For a factory router use Site Setup instead of Detect-by-IP.",
            'useSiteSetup' => true,
        ];
    }

    public function scan(Request $request): JsonResponse
    {
        $devices = $this->detector->scanForMikroTik($request->query('network'));
        try {
            foreach (DB::table('mikrotiks')->whereNotNull('wireguard_ip')->where('wireguard_ip', '!=', '')
                ->get(['router_id', 'router_name', 'wireguard_ip', 'model', 'firmware_version', 'serial_number']) as $row) {
                foreach ($devices as $device) {
                    if (($device['ip'] ?? '') === $row->wireguard_ip) {
                        continue 2;
                    }
                }
                $live = null;
                if (MikrotikDetector::tcpOpen((string) $row->wireguard_ip, 8728, 1500)) {
                    $live = $this->detector->verifyMikrotik((string) $row->wireguard_ip, 8728, 'admin', '');
                }
                $devices[] = [
                    'ip' => $row->wireguard_ip, 'mac' => null, 'source' => 'tunnel',
                    'isMikrotik' => ! empty($live['isMikrotik']),
                    'identity' => $live['identity'] ?? $row->router_name ?? $row->router_id,
                    'model' => $live['model'] ?? $row->model ?? null,
                    'firmware' => $live['firmware'] ?? $row->firmware_version ?? null,
                    'inDatabase' => true,
                    'existingRouter' => ['router_id' => $row->router_id, 'router_name' => $row->router_name],
                ];
            }
        } catch (\Throwable $e) {
            // tunnel scan skipped
        }
        $out = array_map(fn ($d) => [
            'ip' => $d['ip'] ?? null, 'mac' => $d['mac'] ?? null, 'source' => $d['source'] ?? null,
            'isMikrotik' => $d['isMikrotik'] ?? false, 'identity' => $d['identity'] ?? null,
            'model' => $d['model'] ?? null, 'firmware' => $d['firmware'] ?? null,
            'inDatabase' => $d['inDatabase'] ?? false, 'existingRouter' => $d['existingRouter'] ?? null,
        ], $devices);

        return ApiResponse::ok(['count' => count($out), 'devices' => $out]);
    }

    /** SSE stream: detect -> WireGuard -> register -> verify. */
    public function configure(Request $request): Response
    {
        $input = $request->all();
        $ip = (string) ($input['ip'] ?? '');
        $port = (int) ($input['port'] ?? 8728);

        return response()->stream(function () use ($input, $ip, $port): void {
            $send = function (string $event, array $data): void {
                echo 'event: '.$event."\ndata: ".json_encode($data, JSON_UNESCAPED_SLASHES)."\n\n";
                @ob_flush();
                @flush();
            };
            $log = fn (string $message, string $type = 'info') => $send('log', [
                'message' => $message, 'type' => $type, 'time' => gmdate('c'),
            ]);
            $close = fn () => null;

            if ($ip === '') {
                $send('error', ['message' => 'IP address is required']);
                $close();

                return;
            }
            $client = null;
            try {
                $log("Connecting to MikroTik at {$ip}:{$port}...");
                $send('progress', ['step' => 1, 'total' => 8, 'label' => 'Detecting MikroTik']);
                $client = new RouterOsClient([
                    'host' => $ip, 'port' => $port,
                    'user' => $input['user'] ?? 'admin', 'password' => $input['password'] ?? '', 'timeout' => 8,
                ]);
                $client->connect();
                $identity = $client->firstRow('/system/identity');
                $resource = $client->firstRow('/system/resource');
                $board = $client->firstRow('/system/routerboard');
                $routerIdentity = $identity['name'] ?? 'Unknown';
                $model = $board['board-name'] ?? $resource['board-name'] ?? 'Unknown';
                $firmware = $resource['version'] ?? 'Unknown';
                $serial = $board['serial-number'] ?? '';
                $log("Detected: {$routerIdentity} ({$model}, v{$firmware})", 'success');
                $send('detected', ['identity' => $routerIdentity, 'model' => $model, 'firmware' => $firmware, 'serial' => $serial]);

                $send('progress', ['step' => 2, 'total' => 8, 'label' => 'Checking WireGuard support']);
                try {
                    $packages = $client->printRows('/system/package');
                    $wgInstalled = (bool) array_filter($packages, fn ($p) => str_contains(strtolower((string) ($p['name'] ?? '')), 'wireguard')
                        && ($p['disabled'] ?? 'false') !== 'true');
                    $log($wgInstalled ? 'WireGuard package found' : 'WireGuard package not detected — using built-in support', $wgInstalled ? 'success' : 'warn');
                } catch (\Throwable $e) {
                    $log('Could not check WireGuard package: '.$e->getMessage(), 'warn');
                }

                $send('progress', ['step' => 3, 'total' => 8, 'label' => 'Scanning interfaces']);
                $interfaces = $client->printRows('/interface');
                $log('Found '.count($interfaces).' interfaces');
                $send('interfaces', ['interfaces' => array_map(fn ($i) => [
                    'name' => $i['name'] ?? '', 'type' => $i['type'] ?? '', 'running' => $i['running'] ?? 'false',
                ], $interfaces)]);

                $send('progress', ['step' => 4, 'total' => 8, 'label' => 'Generating WireGuard keys']);
                $keys = $this->wireguard->generateKeyPair();
                $preshared = $this->wireguard->generatePresharedKey();
                $assignedIp = $this->wireguard->getNextAvailableIp();
                $serverKeys = $this->wireguard->ensureServerKeys();
                $log('Router assigned IP: '.$assignedIp);
                $send('keys-generated', ['assignedIp' => $assignedIp]);

                $send('progress', ['step' => 5, 'total' => 8, 'label' => 'Configuring WireGuard on router']);
                $commands = [];
                try {
                    $client->command('/interface/wireguard/add', [
                        'name' => 'wg-meshline', 'listen-port' => '13231', 'private-key' => $keys['privateKey'],
                    ]);
                    $commands[] = 'Created interface wg-meshline';
                    $log('Created WireGuard interface wg-meshline', 'success');
                } catch (\Throwable $e) {
                    $log('Interface create: '.$e->getMessage(), 'warn');
                }
                try {
                    $client->command('/interface/wireguard/peers/add', [
                        'interface' => 'wg-meshline', 'public-key' => $serverKeys['publicKey'],
                        'endpoint-address' => $this->wireguard->serverPublicIp,
                        'endpoint-port' => (string) $this->wireguard->listenPort,
                        'allowed-address' => $this->wireguard->networkCidr, 'persistent-keepalive' => '25s',
                    ]);
                    $commands[] = 'Added server peer';
                    $log('Added server as WireGuard peer', 'success');
                } catch (\Throwable $e) {
                    $log('Peer add: '.$e->getMessage(), 'warn');
                }
                try {
                    $client->command('/ip/address/add', ['address' => $assignedIp.'/24', 'interface' => 'wg-meshline']);
                    $commands[] = 'Assigned IP '.$assignedIp;
                } catch (\Throwable $e) {
                    $log('IP assign: '.$e->getMessage(), 'warn');
                }
                try {
                    $client->command('/ip/route/add', ['dst-address' => $this->wireguard->networkCidr, 'gateway' => 'wg-meshline']);
                    $commands[] = 'Added route to server network';
                } catch (\Throwable $e) {
                    $log('Route: '.$e->getMessage(), 'warn');
                }
                try {
                    $client->command('/ip/firewall/filter/add', [
                        'chain' => 'input', 'protocol' => 'udp', 'dst-port' => '13231', 'action' => 'accept', 'comment' => 'WireGuard',
                    ]);
                    $commands[] = 'Added firewall rules';
                } catch (\Throwable $e) {
                    $log('Firewall: '.$e->getMessage(), 'warn');
                }

                $send('progress', ['step' => 6, 'total' => 8, 'label' => 'Registering in database']);
                $routerID = 'router-'.time().'-'.bin2hex(random_bytes(3));
                $name = $input['routerName'] ?: ($routerIdentity ?: 'Router-'.str_replace('.', '-', $ip));
                $added = $this->provisioning->addRouter([
                    'routerID' => $routerID, 'routerName' => $name, 'host' => $ip, 'port' => $port,
                    'user' => $input['user'] ?? 'admin', 'password' => $input['password'] ?? '',
                    'ssid' => $input['ssid'] ?? env('DEFAULT_SSID', 'Meshline WiFi-Free-WiFi'),
                ]);
                if (! $added['success']) {
                    $send('error', ['message' => 'Registration failed: '.$added['message'], 'step' => 'register']);
                    $close();

                    return;
                }
                $this->wireguard->saveRouterWireGuard($routerID, $assignedIp, $keys['publicKey'], $keys['privateKey'], $preshared);
                $lat = $input['latitude'] ?? null;
                $lng = $input['longitude'] ?? null;
                $locName = $name;
                if ((! $lat || ! $lng) && ! empty($input['locationId'])) {
                    $loc = DB::table('locations')->where('id', $input['locationId'])
                        ->first(['name', 'latitude', 'longitude']);
                    if ($loc) {
                        $lat = $loc->latitude ?: $lat;
                        $lng = $loc->longitude ?: $lng;
                        $locName = $loc->name ?: $name;
                    }
                }
                $update = [
                    'setup_status' => 'configured', 'setup_step' => 6, 'setup_completed_at' => now(),
                    'model' => $model, 'firmware_version' => $firmware, 'serial_number' => $serial, 'updated_at' => now(),
                ];
                if ($lat && $lng) {
                    $update += ['latitude' => $lat, 'longitude' => $lng,
                        'location_name' => $locName, 'location_id' => $input['locationId'] ?? null];
                }
                DB::table('mikrotiks')->where('router_id', $routerID)->update($update);

                $send('progress', ['step' => 7, 'total' => 8, 'label' => 'Configuring server WireGuard']);
                $serverAdd = $this->wireguard->addPeerToServer($routerID, $keys['publicKey'], $assignedIp);
                $log($serverAdd['success'] ? 'Router peer added to server' : 'Server peer add warning: '.($serverAdd['message'] ?? 'unknown'),
                    $serverAdd['success'] ? 'success' : 'warn');

                $send('progress', ['step' => 8, 'total' => 8, 'label' => 'Verifying connection']);
                $tunnelOk = MikrotikDetector::tcpOpen($assignedIp, 8728, 2000);
                $log($tunnelOk ? 'Tunnel verified — '.$assignedIp.' is reachable' : 'Tunnel ping failed (may take a moment to establish)',
                    $tunnelOk ? 'success' : 'warn');
                $client->close();
                $client = null;
                $log('Setup complete!', 'success');
                $send('done', [
                    'success' => true,
                    'router' => ['routerID' => $routerID, 'routerName' => $name, 'ip' => $ip,
                        'model' => $model, 'firmware' => $firmware,
                        'wireguard' => ['assignedIp' => $assignedIp, 'publicKey' => $keys['publicKey']]],
                    'tunnelVerified' => $tunnelOk, 'commands' => $commands,
                ]);
            } catch (\Throwable $e) {
                $log('Setup failed: '.$e->getMessage(), 'error');
                $send('error', ['message' => $e->getMessage()]);
            } finally {
                try {
                    $client?->close();
                } catch (\Throwable $e) {
                    // ignore
                }
                $close();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function bootstrap(): JsonResponse
    {
        return ApiResponse::ok(['script' => ProvisioningService::generateBootstrapScript()]);
    }

    public function serverStatus(): JsonResponse
    {
        return ApiResponse::ok([
            'server' => $this->wireguard->getServerStatus(),
            'peers' => $this->wireguard->getWireGuardPeers()['peers'] ?? [],
        ]);
    }

    public function applyServer(): JsonResponse
    {
        return ApiResponse::json($this->wireguard->applyServerConfig());
    }
}
