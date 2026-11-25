<?php

namespace App\Http\Controllers\Router;

use App\Http\Controllers\Controller;
use App\Services\Routers\MikrotikDetector;
use App\Services\Routers\ProvisioningService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Port of routes/autoDetect.js. */
class AutoDetectController extends Controller
{
    use RouterResponseTrait;

    public function __construct(
        private readonly MikrotikDetector $detector = new MikrotikDetector(),
        private readonly ProvisioningService $provisioning = new ProvisioningService(),
    ) {}

    public function scan(Request $request): JsonResponse
    {
        try {
            $devices = $this->detector->scanForMikroTik($request->query('network'));
            $safe = array_map(fn ($d) => [
                'ip' => $d['ip'] ?? null, 'mac' => $d['mac'] ?? null, 'source' => $d['source'] ?? null,
                'confidence' => $d['confidence'] ?? null, 'isMikrotik' => $d['isMikrotik'] ?? false,
                'identity' => $d['identity'] ?? null, 'model' => $d['model'] ?? null,
                'firmware' => $d['firmware'] ?? null, 'inDatabase' => $d['inDatabase'] ?? false,
                'existingRouter' => $d['existingRouter'] ?? null,
            ], $devices);

            return ApiResponse::ok([
                'message' => 'Found '.count($safe).' MikroTik device(s)',
                'devices' => $safe, 'networkInterfaces' => $this->detector->getNetworkInfo(),
            ]);
        } catch (\Throwable $e) {
            return ApiResponse::error('Scan failed: '.$e->getMessage());
        }
    }

    public function status(): JsonResponse
    {
        $detected = $this->detector->getDetected();

        return ApiResponse::ok([
            'detectedCount' => count($detected), 'devices' => $detected,
            'networkInterfaces' => $this->detector->getNetworkInfo(),
            'isScanning' => $this->detector->isScanning,
        ]);
    }

    public function verify(Request $request): JsonResponse
    {
        $ip = (string) $request->input('ip', '');
        if ($ip === '') {
            return ApiResponse::fail('IP address is required');
        }
        $result = $this->detector->verifyMikrotik(
            $ip, (int) $request->input('port', 8728),
            (string) $request->input('user', 'admin'), (string) $request->input('password', '')
        );

        return ApiResponse::ok(['ip' => $ip] + $result);
    }

    public function quickSetup(Request $request): JsonResponse
    {
        $ip = (string) $request->input('ip', '');
        $serverIP = (string) $request->input('serverIP', '');
        if ($ip === '') {
            return ApiResponse::fail('Router IP is required');
        }
        if ($serverIP === '') {
            return ApiResponse::fail('Server IP is required');
        }
        $port = (int) $request->input('port', 8728);
        $user = (string) $request->input('user', 'admin');
        $password = (string) $request->input('password', env('MIKROTIK_DEFAULT_PASSWORD', 'admin'));
        $verification = $this->detector->verifyMikrotik($ip, $port, $user, $password);
        if (empty($verification['isMikrotik'])) {
            return ApiResponse::ok(['success' => false, 'message' => "Device at {$ip} is not a MikroTik router", 'step' => 'verify']);
        }
        $routerID = 'router-'.time().'-'.bin2hex(random_bytes(3));
        $name = $request->input('routerName') ?: ($verification['identity'] ?? 'Router-'.str_replace('.', '-', $ip));
        $ssid = $request->input('ssid', env('DEFAULT_SSID', 'HotBando-Free-WiFi'));
        $added = $this->provisioning->addRouter([
            'routerID' => $routerID, 'routerName' => $name, 'host' => $ip, 'port' => $port,
            'user' => $user, 'password' => $password, 'ssid' => $ssid,
        ]);
        if (! $added['success']) {
            return ApiResponse::ok(['success' => false, 'message' => 'Failed to register router: '.$added['message'], 'step' => 'register']);
        }
        $configured = $this->provisioning->configureRouter($routerID, [
            'serverIP' => $serverIP, 'radiusSecret' => $request->input('radiusSecret', env('RADIUS_SECRET', '')),
            'hotspotInterface' => 'wlan1', 'hotspotIP' => env('HOTSPOT_IP', '10.5.50.1'),
            'hotspotNet' => env('HOTSPOT_SUBNET', '10.5.50.0'), 'ssid' => $ssid,
        ]);
        if (! $configured['success']) {
            return ApiResponse::ok(['success' => false, 'message' => 'Configuration failed: '.$configured['message'],
                'step' => 'configure', 'routerID' => $routerID]);
        }
        $lat = $request->input('latitude');
        $lng = $request->input('longitude');
        $update = [
            'setup_status' => 'configured', 'setup_step' => 6, 'setup_completed_at' => now(), 'updated_at' => now(),
        ];
        if ($lat && $lng) {
            $update += [
                'latitude' => $lat, 'longitude' => $lng,
                'location_name' => $request->input('locationName') ?: $name,
                'location_address' => $request->input('locationAddress', ''),
                'location_city' => $request->input('locationCity', ''),
                'location_region' => $request->input('locationRegion', ''),
                'location_type' => $request->input('locationType', 'other'),
            ];
            DB::table('router_location_history')->insert([
                'router_id' => $routerID, 'latitude' => $lat, 'longitude' => $lng,
                'location_name' => $request->input('locationName') ?: $name,
                'location_type' => $request->input('locationType', 'other'), 'moved_at' => now(),
            ]);
        }
        DB::table('mikrotiks')->where('router_id', $routerID)->update($update);

        return ApiResponse::ok([
            'success' => true, 'message' => 'Router configured successfully!',
            'router' => [
                'routerID' => $routerID, 'routerName' => $name, 'ip' => $ip, 'ssid' => $ssid,
                'model' => $verification['model'] ?? null, 'firmware' => $verification['firmware'] ?? null,
                'location' => ['latitude' => $lat, 'longitude' => $lng,
                    'name' => $request->input('locationName') ?: $name,
                    'city' => $request->input('locationCity'), 'region' => $request->input('locationRegion')],
            ],
            'configuration' => $configured, 'verification' => $this->provisioning->verifyConfiguration($routerID),
            'nextSteps' => ['Connect to WiFi: '.$ssid, 'Open browser: http://10.5.50.1', 'Start selling vouchers!'],
        ]);
    }

    public function moveRouter(Request $request): JsonResponse
    {
        $routerID = (string) $request->input('routerID', '');
        if ($routerID === '') {
            return ApiResponse::fail('Router ID is required');
        }
        $current = DB::table('mikrotiks')->where('router_id', $routerID)
            ->first(['location_name', 'latitude', 'longitude']);
        if (! $current) {
            return ApiResponse::fail('Router not found', 404);
        }
        $name = (string) $request->input('newLocationName', '');
        DB::table('mikrotiks')->where('router_id', $routerID)->update([
            'latitude' => $request->input('newLatitude'), 'longitude' => $request->input('newLongitude'),
            'location_name' => $name, 'location_address' => $request->input('newLocationAddress', ''),
            'location_city' => $request->input('newLocationCity', ''),
            'location_region' => $request->input('newLocationRegion', ''),
            'location_type' => $request->input('newLocationType', 'other'), 'last_moved_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('router_location_history')->insert([
            'router_id' => $routerID, 'latitude' => $request->input('newLatitude'),
            'longitude' => $request->input('newLongitude'), 'location_name' => $name,
            'location_type' => $request->input('newLocationType', 'other'),
            'move_reason' => $request->input('moveReason', 'Location updated'), 'moved_at' => now(),
        ]);

        return ApiResponse::ok([
            'message' => 'Router moved from "'.($current->location_name ?? '').'" to "'.$name.'"',
            'previousLocation' => (array) $current,
            'newLocation' => ['latitude' => $request->input('newLatitude'),
                'longitude' => $request->input('newLongitude'), 'name' => $name],
        ]);
    }

    public function mapData(): JsonResponse
    {
        $rows = DB::table('mikrotiks')
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->select('router_id', 'router_name', 'host', 'status', 'latitude', 'longitude',
                'location_name', 'location_address', 'location_city', 'location_region',
                'location_type', 'ssid', 'model', 'setup_status', 'last_seen')
            ->orderBy('location_name')->get()->all();

        return ApiResponse::ok(['count' => count($rows), 'routers' => $rows]);
    }

    public function locationHistory(string $routerID): JsonResponse
    {
        $rows = DB::table('router_location_history')->where('router_id', $routerID)
            ->orderByDesc('moved_at')->limit(50)->get()->all();

        return ApiResponse::ok(['count' => count($rows), 'history' => $rows]);
    }
}
