<?php

namespace App\Http\Controllers\Router;

use App\Http\Controllers\Controller;
use App\Services\Routers\CapabilityException;
use App\Services\Routers\MikrotikDriver;
use App\Services\Routers\ProvisioningService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Port of routes/setupWizard.js (guided 7-step setup). */
class SetupWizardController extends Controller
{
    use RouterResponseTrait;

    public function __construct(private readonly ProvisioningService $provisioning = new ProvisioningService()) {}

    public function status(string $routerID): JsonResponse
    {
        $row = is_numeric($routerID)
            ? DB::table('mikrotiks')->where('id', (int) $routerID)->first()
            : DB::table('mikrotiks')->where('router_id', $routerID)->first();
        if (! $row) {
            return ApiResponse::ok([
                'status' => 'not_registered', 'message' => 'Router isjaa registered na system',
                'steps' => self::steps(), 'currentStep' => 0,
            ]);
        }
        $connection = 'offline';
        $info = null;
        try {
            $session = $this->session((string) ($row->router_id ?: $routerID));
            if ($session) {
                $connection = 'online';
                $info = $session->driver->identity($session);
                $session->driver->disconnect($session);
            }
        } catch (\Throwable $e) {
            // offline
        }
        $config = ['configured' => false, 'checks' => []];
        try {
            $config = $this->provisioning->verifyConfiguration((string) ($row->router_id ?: $routerID));
        } catch (\Throwable $e) {
            // keep unknown
        }
        $current = self::currentStep($connection, $config);

        return ApiResponse::ok([
            'router' => [
                'id' => $row->id, 'router_id' => $row->router_id, 'router_name' => $row->router_name,
                'host' => $row->host, 'status' => $row->status, 'ssid' => $row->ssid,
            ],
            'connection' => $connection, 'routerInfo' => $info, 'configuration' => $config,
            'steps' => self::steps(), 'currentStep' => $current,
            'message' => self::messages()[$current] ?? 'Unknown step',
        ]);
    }

    public function instructions(string $routerID, string $step): JsonResponse
    {
        $number = (int) $step;
        if ($number < 1 || $number > 7) {
            return ApiResponse::fail('Invalid step number');
        }

        return ApiResponse::ok(['instructions' => self::stepInstructions($number, $routerID)]);
    }

    public function execute(Request $request, string $routerID, string $step): JsonResponse
    {
        $number = (int) $step;
        if ($number < 1 || $number > 7) {
            return ApiResponse::fail('Invalid step number');
        }
        try {
            $result = match ($number) {
                1 => $this->stepRegister($routerID, $request->all()),
                2 => $this->stepConnection($routerID),
                3 => $this->stepHotspot($routerID, $request->all()),
                4 => $this->stepWifi($routerID, $request->all()),
                5 => $this->stepLocation($routerID, $request->all()),
                6 => $this->stepRadius($routerID, $request->all()),
                7 => $this->stepVerify($routerID),
            };

            return ApiResponse::json([
                'success' => $result['success'],
                'step' => $number,
                'message' => $result['message'],
                'data' => $result['data'] ?? null,
                'nextStep' => ! empty($result['success']) ? $number + 1 : $number,
                'instructions' => $result['instructions'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return ApiResponse::error('Step execution failed: '.$e->getMessage());
        }
    }

    public function quickSetup(Request $request, string $routerID): JsonResponse
    {
        $body = $request->all();
        $ssid = $body['ssid'] ?? env('DEFAULT_SSID', 'Meshline WiFi-Free-WiFi');
        $added = $this->provisioning->addRouter([
            'routerID' => $routerID, 'routerName' => $body['routerName'] ?? "Router-{$routerID}",
            'host' => $body['host'] ?? '10.7.0.4', 'port' => 8728,
            'user' => $body['user'] ?? 'admin', 'password' => $body['password'] ?? 'admin', 'ssid' => $ssid,
        ]);
        if (! $added['success']) {
            return ApiResponse::ok(['success' => false, 'message' => 'Failed to add router: '.$added['message'],
                'step' => 1, 'instructions' => self::stepInstructions(1, $routerID)]);
        }
        $configured = $this->provisioning->configureRouter($routerID, [
            'serverIP' => $body['serverIP'] ?? '',
            'radiusSecret' => $body['radiusSecret'] ?? env('RADIUS_SECRET', ''),
            'hotspotInterface' => $body['hotspotInterface'] ?? 'wlan1',
            'hotspotIP' => $body['hotspotIP'] ?? env('HOTSPOT_IP', '10.5.50.1'),
            'hotspotNet' => $body['hotspotNet'] ?? env('HOTSPOT_SUBNET', '10.5.50.0'),
            'ssid' => $ssid,
        ]);
        if (! $configured['success']) {
            return ApiResponse::ok(['success' => false, 'message' => 'Configuration failed: '.$configured['message'],
                'step' => 3, 'data' => $configured, 'instructions' => self::stepInstructions(3, $routerID)]);
        }

        return ApiResponse::ok([
            'success' => true, 'message' => 'Router configured successfully!',
            'router' => ['routerID' => $routerID, 'routerName' => $body['routerName'] ?? "Router-{$routerID}",
                'ssid' => $ssid, 'hotspotIP' => $body['hotspotIP'] ?? env('HOTSPOT_IP', '10.5.50.1'),
                'serverIP' => $body['serverIP'] ?? ''],
            'configuration' => $configured, 'verification' => $this->provisioning->verifyConfiguration($routerID),
            'nextSteps' => ['Connect to WiFi: '.$ssid, 'Open browser and test login', 'Voucher zako ziko tayari kuuza!'],
        ]);
    }

    public function unconfigured(): JsonResponse
    {
        $rows = DB::table('mikrotiks')
            ->whereNull('setup_status')->orWhere('setup_status', '!=', 'configured')
            ->orderByDesc('created_at')->get()->all();
        $routers = [];
        foreach ($rows as $row) {
            $status = 'offline';
            $session = null;
            try {
                $session = $this->session((string) $row->router_id);
                if ($session) {
                    $status = 'online';
                }
            } catch (\Throwable $e) {
                // offline
            } finally {
                if ($session) {
                    $session->driver->disconnect($session);
                }
            }
            $safe = $this->stripSecrets($row);
            $safe['connectionStatus'] = $status;
            $safe['needsSetup'] = true;
            $routers[] = $safe;
        }

        return ApiResponse::ok([
            'count' => count($routers), 'routers' => $routers,
            'message' => $routers ? count($routers).' router(s) need configuration' : 'All routers are configured',
        ]);
    }

    // ---------------- Step implementations ----------------

    private function stepRegister(string $id, array $config): array
    {
        $result = $this->provisioning->addRouter([
            'routerID' => $id,
            'routerName' => $config['routerName'] ?? "Router-{$id}",
            'host' => $config['host'] ?? '192.168.88.1',
            'user' => $config['user'] ?? 'admin',
            'password' => $config['password'] ?? env('MIKROTIK_DEFAULT_PASSWORD', 'admin'),
            'ssid' => $config['ssid'] ?? env('DEFAULT_SSID', 'Meshline WiFi-Free-WiFi'),
        ]);

        return [
            'success' => $result['success'],
            'message' => $result['success'] ? 'Router imeongezwa kwenye system!' : 'Imeshindwa kuongeza router: '.$result['message'],
            'data' => $result['data'] ?? null,
            'instructions' => $result['success']
                ? ['title' => '✅ Router Imeongezwa!', 'next' => 'Sasa ita-connect na kuconfigure automatically']
                : self::stepInstructions(1, $id),
        ];
    }

    private function stepConnection(string $id): array
    {
        try {
            $session = $this->session($id);
            if (! $session) {
                throw new \RuntimeException('Router not found');
            }
            $data = $session->driver->identity($session);
            $session->driver->disconnect($session);

            return ['success' => true, 'message' => 'Connection successful!', 'data' => $data,
                'instructions' => ['title' => '✅ Connection Imefanikiwa!', 'routerInfo' => $data, 'next' => 'Sasa tutaweke configuration']];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Connection failed: '.$e->getMessage(),
                'instructions' => self::stepInstructions(2, $id)];
        }
    }

    private function stepHotspot(string $id, array $config): array
    {
        $result = $this->provisioning->configureRouter($id, $config + [
            'serverIP' => $config['serverIP'] ?? '', 'radiusSecret' => $config['radiusSecret'] ?? env('RADIUS_SECRET', ''),
            'hotspotInterface' => $config['hotspotInterface'] ?? 'wlan1',
            'hotspotIP' => $config['hotspotIP'] ?? env('HOTSPOT_IP', '10.5.50.1'),
            'hotspotNet' => $config['hotspotNet'] ?? env('HOTSPOT_SUBNET', '10.5.50.0'),
            'ssid' => $config['ssid'] ?? env('DEFAULT_SSID', 'Meshline WiFi-Free-WiFi'),
        ]);

        return [
            'success' => $result['success'],
            'message' => $result['success'] ? 'Hotspot configured successfully!' : 'Configuration failed: '.$result['message'],
            'data' => $result,
            'instructions' => $result['success']
                ? ['title' => '✅ Hotspot Imeconfigure!', 'config' => $result['config'] ?? [], 'next' => 'Hotspot iko tayari']
                : self::stepInstructions(3, $id),
        ];
    }

    private function stepWifi(string $id, array $config): array
    {
        $ssid = $config['ssid'] ?? env('DEFAULT_SSID', 'Meshline WiFi-Free-WiFi');
        $iface = $config['interfaceName'] ?? 'wlan1';
        $session = null;
        try {
            $session = $this->session($id);
            if (! $session || ! $session->driver->supports('ssid')) {
                throw new CapabilityException('setSsid', $session?->driver->name() ?? 'router');
            }
            $session->driver->setSsid($session, $iface, $ssid);

            return ['success' => true, 'message' => 'WiFi configured!', 'data' => ['ssid' => $ssid, 'interface' => $iface],
                'instructions' => ['title' => '✅ WiFi Imeconfigure!', 'ssid' => $ssid]];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Error: '.$e->getMessage(), 'instructions' => self::stepInstructions(4, $id)];
        } finally {
            if ($session) {
                $session->driver->disconnect($session);
            }
        }
    }

    private function stepLocation(string $id, array $config): array
    {
        if (empty($config['latitude']) || empty($config['longitude'])) {
            return ['success' => false, 'message' => 'Weka GPS coordinates (latitude na longitude) kwanza',
                'instructions' => self::stepInstructions(5, $id)];
        }
        $numeric = ctype_digit($id);
        $column = $numeric ? 'id' : 'router_id';
        if (! DB::table('mikrotiks')->where($column, $numeric ? (int) $id : $id)->exists()) {
            return ['success' => false, 'message' => 'Router haijapatikana. Sajili router kwanza (Hatua ya 1).',
                'instructions' => self::stepInstructions(1, $id)];
        }
        $name = $config['locationName'] ?? "Router-{$id}";
        DB::table('mikrotiks')->where($column, $numeric ? (int) $id : $id)->update([
            'latitude' => $config['latitude'], 'longitude' => $config['longitude'],
            'location_name' => $name, 'location_address' => $config['locationAddress'] ?? '',
            'location_city' => $config['locationCity'] ?? '', 'location_region' => $config['locationRegion'] ?? '',
            'location_type' => $config['locationType'] ?? 'other', 'setup_step' => 5, 'updated_at' => now(),
        ]);
        DB::table('router_location_history')->insert([
            'router_id' => $id, 'latitude' => $config['latitude'], 'longitude' => $config['longitude'],
            'location_name' => $name, 'location_type' => $config['locationType'] ?? 'other', 'moved_at' => now(),
        ]);

        return ['success' => true, 'message' => 'Eneo limehifadhiwa!',
            'data' => ['latitude' => $config['latitude'], 'longitude' => $config['longitude'], 'locationName' => $name],
            'instructions' => ['title' => '✅ Eneo Limehifadhiwa!', 'next' => 'Sasa tutaweka RADIUS configuration']];
    }

    private function stepRadius(string $id, array $config): array
    {
        $serverIP = $config['serverIP'] ?? null;
        if (! $serverIP) {
            return ['success' => false, 'message' => 'Server IP haijatolewa. Weka IP ya Meshline WiFi server kwenye Hatua ya 3.',
                'instructions' => self::stepInstructions(6, $id)];
        }
        $verify = $this->provisioning->verifyConfiguration($id);
        if (! empty($verify['configured'])) {
            return ['success' => true, 'message' => 'RADIUS configured!', 'data' => $verify['checks'],
                'instructions' => ['title' => '✅ RADIUS Imeconfigure!', 'checks' => $verify['checks']]];
        }
        $result = $this->provisioning->configureRouter($id, $config + [
            'serverIP' => $serverIP, 'radiusSecret' => $config['radiusSecret'] ?? env('RADIUS_SECRET', ''),
            'hotspotInterface' => $config['hotspotInterface'] ?? 'wlan1',
            'hotspotIP' => $config['hotspotIP'] ?? env('HOTSPOT_IP', '10.5.50.1'),
            'hotspotNet' => $config['hotspotNet'] ?? env('HOTSPOT_SUBNET', '10.5.50.0'),
            'ssid' => $config['ssid'] ?? env('DEFAULT_SSID', 'Meshline WiFi-Free-WiFi'),
        ]);

        return ['success' => $result['success'],
            'message' => $result['success'] ? 'RADIUS configured successfully!' : 'RADIUS configuration failed: '.$result['message'],
            'data' => $result['config'] ?? null, 'instructions' => $result['success']
                ? ['title' => '✅ RADIUS Imeconfigure!'] : self::stepInstructions(6, $id)];
    }

    private function stepVerify(string $id): array
    {
        $verify = $this->provisioning->verifyConfiguration($id);
        if (! empty($verify['configured'])) {
            return ['success' => true, 'message' => '🎉 Router iko tayari kwa matumizi!', 'data' => $verify,
                'instructions' => ['title' => '🎉 Ukamilifu!', 'checks' => $verify['checks'],
                    'nextSteps' => ['1. Generate vouchers kwenye dashboard', '2. Weka bei ya vouchers', '3. Anza kuuza!']]];
        }

        return ['success' => false, 'message' => 'Configuration incomplete', 'data' => $verify,
            'instructions' => self::stepInstructions(7, $id)];
    }

    // ---------------- Static wizard metadata ----------------

    private static function steps(): array
    {
        return [
            ['step' => 1, 'name' => 'Register Router', 'description' => 'Ongeza router kwenye system', 'icon' => '🔌', 'autoConfig' => false, 'manualRequired' => true],
            ['step' => 2, 'name' => 'Test Connection', 'description' => 'Test kama server inaweza kuconnect na router', 'icon' => '🔗', 'autoConfig' => true, 'manualRequired' => false],
            ['step' => 3, 'name' => 'Configure Hotspot', 'description' => 'Weka hotspot server, DHCP, na captive portal', 'icon' => '📡', 'autoConfig' => true, 'manualRequired' => false],
            ['step' => 4, 'name' => 'Configure WiFi', 'description' => 'Weka SSID na wireless settings', 'icon' => '📶', 'autoConfig' => true, 'manualRequired' => false],
            ['step' => 5, 'name' => 'Location', 'description' => 'Weka GPS na eneo la router', 'icon' => '📍', 'autoConfig' => false, 'manualRequired' => true],
            ['step' => 6, 'name' => 'Configure RADIUS', 'description' => 'Weka RADIUS server kwa authentication', 'icon' => '🔐', 'autoConfig' => true, 'manualRequired' => false],
            ['step' => 7, 'name' => 'Test & Verify', 'description' => 'Thibitisha kila kitu kinafanya kazi', 'icon' => '✅', 'autoConfig' => true, 'manualRequired' => false],
        ];
    }

    private static function messages(): array
    {
        return [
            1 => 'Chomeka MikroTik na uconnect na server. Router itaji-register automatically.',
            2 => 'Server inajaribu kuconnect na router. Hakikisha router iko online.',
            3 => 'Inaconfigure hotspot server, DHCP, na captive portal.',
            4 => 'Inaweka WiFi SSID na wireless settings.',
            5 => 'Weka GPS coordinates na jina la eneo la router.',
            6 => 'Inaweka RADIUS server kwa user authentication.',
            7 => 'Inathibitisha kila kitu kinafanya kazi. Unaweza kuanza kutumia!',
        ];
    }

    private static function currentStep(string $connection, array $config): int
    {
        if ($connection === 'offline') {
            return 2;
        }
        if (! empty($config['configured'])) {
            return 7;
        }
        $checks = $config['checks'] ?? [];
        if (($checks['radius'] ?? '') === 'ok') {
            return 6;
        }
        if (($checks['hotspotServer'] ?? '') === 'ok') {
            return 5;
        }
        if (($checks['dhcp'] ?? '') === 'ok') {
            return 4;
        }

        return 3;
    }

    private static function stepInstructions(int $step, string $routerID): array
    {
        return match ($step) {
            1 => [
                'title' => '🔌 Hatua ya 1: Ongeza Router', 'description' => 'Chomeka MikroTik na uconnect na internet',
                'steps' => ['1. Chomeka ethernet cable kwenye MikroTik port 1 (internet in)', '2. Hakikisha WiFi iko on', '3. Router itaji-register automatically', '4. Au weka manual: admin/admin kwenye browser'],
                'manualSteps' => ['1. Open WinBox/WebFig', '2. Connect na IP ya router (default: 192.168.88.1)', '3. Run script: /import file-name=mikrotik-auto-register.rsc'],
                'expectedResult' => 'Router inaonekana kwenye dashboard',
            ],
            2 => [
                'title' => '🔗 Hatua ya 2: Test Connection', 'description' => 'Server inajaribu kuconnect na router',
                'steps' => ['1. Server ita-connect na router kupitia API (port 8728)', '2. Ita-test kama router inajibu', '3. Itaonyesha: model, firmware, uptime'],
                'expectedResult' => 'Connection successful, router info inaonekana',
            ],
            3 => [
                'title' => '📡 Hatua ya 3: Configure Hotspot', 'description' => 'Inaweka hotspot server, DHCP, na captive portal',
                'steps' => ['1. Ina-create hotspot server profile', '2. Inaweka DHCP server', '3. Inaweka DNS', '4. Inaweka firewall NAT', '5. Inaweka walled garden'],
                'expectedResult' => 'Hotspot server iko active, DHCP iko working',
            ],
            4 => [
                'title' => '📶 Hatua ya 4: Configure WiFi', 'description' => 'Inaweka WiFi SSID na wireless settings',
                'steps' => ['1. Inaweka SSID (jina la WiFi)', '2. Inaweka security (WPA2/WPA3)', '3. Ina-enable wireless interface'],
                'expectedResult' => 'WiFi inaonekana kwenye simu/computer',
            ],
            5 => [
                'title' => '📍 Hatua ya 5: Weka Eneo la Router', 'description' => 'Weka GPS coordinates na taarifa za eneo',
                'steps' => ['1. Bonyeza "Pata GPS Location Sasa"', '2. Au weka coordinates kutoka Google Maps', '3. Weka jina la eneo, mji na mkoa'],
                'expectedResult' => 'Router inaonekana kwenye ramani',
            ],
            6 => [
                'title' => '🔐 Hatua ya 6: Configure RADIUS', 'description' => 'Inaweka RADIUS server kwa user authentication',
                'steps' => ['1. Ina-add RADIUS server (FreeRADIUS)', '2. Ina-enable RADIUS kwenye hotspot', '3. Inaweka RADIUS secret'],
                'expectedResult' => 'RADIUS server iko connected',
            ],
            7 => [
                'title' => '✅ Hatua ya 7: Test & Verify', 'description' => 'Thibitisha kila kitu kinafanya kazi',
                'steps' => ['1. Connect to WiFi na simu/computer', '2. Open browser (itafunguka captive portal)', '3. Jisajili/login na nambari ya simu', '4. Thibitisha unapata internet'],
                'expectedResult' => 'Unaweza kuingia internet baada ya login',
            ],
            default => [],
        };
    }
}
