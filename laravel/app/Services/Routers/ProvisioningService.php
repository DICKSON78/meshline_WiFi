<?php

namespace App\Services\Routers;

use App\Support\Encryption;
use Illuminate\Support\Facades\DB;

/**
 * Port of utils/routerProvision.js + utils/routerManager.js + wireguard.js glue.
 *
 * Two-phase zero-touch flow:
 *   register  -> PENDING router, reserved WireGuard IP, "wait & poll" script;
 *   connect   -> owner approves, server peer published, CONFIGURING;
 *   provision -> approved router downloads the full .rsc script.
 */
class ProvisioningService
{
    public function __construct(
        private readonly RouterManager $manager = new RouterManager(),
        private readonly WireGuardService $wireguard = new WireGuardService(),
    ) {}

    public static function registrationSecret(): string
    {
        return (string) (env('ROUTER_REGISTRATION_SECRET') ?: env('RADIUS_SECRET') ?: '');
    }

    public static function publicHost(): string
    {
        return (string) (env('SERVER_PUBLIC_HOST') ?: env('SERVER_PUBLIC_IP') ?: 'hotbandowifi.online');
    }

    public static function baseUrl(): string
    {
        $configured = trim((string) (env('PUBLIC_BASE_URL') ?: env('APP_URL') ?: ''));
        if ($configured !== '' && ! preg_match('#^https?://(?:localhost|127\.0\.0\.1|0\.0\.0\.0)(?::\d+)?$#i', $configured)) {
            return rtrim($configured, '/');
        }

        return 'https://'.self::publicHost();
    }

    public static function fetchMode(): string
    {
        return str_starts_with(self::baseUrl(), 'https://') ? 'https' : 'http';
    }

    private static function defaultSsid(): string
    {
        return (string) env('DEFAULT_SSID', 'HotBando-Free-WiFi');
    }

    private static function encryptSecret(string $value): string
    {
        try {
            return Encryption::encrypt($value) ?? $value;
        } catch (\Throwable $e) {
            report($e);

            return $value; // keep booting when ENCRYPTION_KEY is not configured
        }
    }

    // ------------------------------------------------------------------
    // Router lifecycle
    // ------------------------------------------------------------------

    /** Resolve or create a router row by router_id / serial number. */
    public function resolveRouter(array $payload): array
    {
        $serial = $payload['serial'] ?? $payload['serial_number'] ?? null;
        $routerId = $payload['router_id'] ?? $payload['device_id'] ?? null;
        $query = DB::table('mikrotiks');
        if ($routerId) {
            $query->where('router_id', $routerId)->orWhere('serial_number', $serial);
        } elseif ($serial) {
            $query->where('serial_number', $serial);
        } else {
            throw new \InvalidArgumentException('serial_number is required');
        }
        $row = $query->first();
        if ($row) {
            return [(array) $row, false];
        }

        $id = $routerId ?: 'HB-'.substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($serial ?: time())) ?: '', 0, 24);
        $now = now();
        DB::table('mikrotiks')->insert([
            'router_id' => $id,
            'router_name' => $payload['identity'] ?? "HotBando {$id}",
            'host' => '',
            'port' => 8728,
            'user' => 'admin',
            'password' => self::encryptSecret('admin'),
            'ssid' => self::defaultSsid(),
            'status' => 'offline',
            'setup_status' => 'pending',
            'setup_step' => 1,
            'serial_number' => $serial,
            'model' => $payload['model'] ?? null,
            'firmware_version' => $payload['firmware'] ?? null,
            'location_id' => $payload['location_id'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [(array) DB::table('mikrotiks')->where('router_id', $id)->first(), true];
    }

    /** PHASE 1: register as PENDING and return the wait script. */
    public function registerRouter(array $payload): array
    {
        [$router, $created] = $this->resolveRouter($payload);
        $routerId = (string) $router['router_id'];
        if (empty($router['wireguard_public_key']) || empty($router['wireguard_private_key'])) {
            $keys = $this->wireguard->generateKeyPair();
            $preshared = $this->wireguard->generatePresharedKey();
            $ip = $router['wireguard_ip'] ?: $this->wireguard->getNextAvailableIp();
            $this->wireguard->saveRouterWireGuard($routerId, $ip, $keys['publicKey'], $keys['privateKey'], $preshared);
        }
        DB::table('mikrotiks')->where('router_id', $routerId)->update([
            'status' => 'offline',
            'setup_status' => 'pending',
            'setup_step' => 1,
            'ssid' => $payload['ssid'] ?? $router['ssid'] ?? self::defaultSsid(),
            'last_seen' => now(),
            'updated_at' => now(),
        ]);
        $current = (array) DB::table('mikrotiks')->where('router_id', $routerId)->first();

        return [
            'router' => $current,
            'assignedIp' => $current['wireguard_ip'] ?? null,
            'serverPublicIp' => self::publicHost(),
            'wgPort' => $this->wireguard->listenPort,
            'wgNetwork' => $this->wireguard->networkCidr,
            'script' => self::generateWaitScript($current),
            'pending' => true,
            'created' => $created,
        ];
    }

    /** PHASE 2: owner clicked CONNECT. */
    public function connectRouter(string $routerId, array $payload = []): array
    {
        $row = DB::table('mikrotiks')->where('router_id', $routerId)->first();
        if (! $row) {
            throw new \RuntimeException('Router not found');
        }
        $router = (array) $row;
        if (empty($router['wireguard_public_key']) || empty($router['wireguard_private_key']) || empty($router['wireguard_ip'])) {
            $keys = $this->wireguard->generateKeyPair();
            $ip = $router['wireguard_ip'] ?: $this->wireguard->getNextAvailableIp();
            $this->wireguard->saveRouterWireGuard($routerId, $ip, $keys['publicKey'], $keys['privateKey'], $this->wireguard->generatePresharedKey());
        }
        $current = (array) DB::table('mikrotiks')->where('router_id', $routerId)->first();
        $peer = $this->wireguard->addPeerToServer($routerId, (string) $current['wireguard_public_key'], (string) $current['wireguard_ip']);
        if (empty($peer['success'])) {
            throw new \RuntimeException('Failed to add WireGuard peer: '.($peer['message'] ?? 'unknown'));
        }
        DB::table('mikrotiks')->where('router_id', $routerId)->update([
            'host' => $current['wireguard_ip'],
            'status' => 'offline',
            'setup_status' => 'configuring',
            'setup_step' => 2,
            'ssid' => $payload['ssid'] ?? $current['ssid'] ?? self::defaultSsid(),
            'last_seen' => now(),
            'updated_at' => now(),
        ]);
        $final = (array) DB::table('mikrotiks')->where('router_id', $routerId)->first();

        return [
            'router' => $final,
            'routerId' => $routerId,
            'assignedIp' => $final['wireguard_ip'] ?? null,
            'wireguardIp' => $final['wireguard_ip'] ?? null,
            'serverPublicKey' => $this->wireguard->ensureServerKeys()['publicKey'],
            'serverPublicIp' => self::publicHost(),
            'wgPort' => $this->wireguard->listenPort,
            'wgNetwork' => $this->wireguard->networkCidr,
            'status' => 'configuring',
            'script' => self::generateProvisionScript([
                'router' => $final,
                'router_id' => $routerId,
                'router_name' => $final['router_name'] ?? $routerId,
                'wireguard_ip' => $final['wireguard_ip'] ?? null,
                'ssid' => $final['ssid'] ?? self::defaultSsid(),
                'wireguard' => [
                    'routerPrivateKey' => $final['wireguard_private_key'] ?? '',
                    'serverPublicKey' => $this->wireguard->ensureServerKeys()['publicKey'],
                    'serverPublicIp' => self::publicHost(),
                    'serverIp' => $this->wireguard->serverIp,
                    'port' => $this->wireguard->listenPort,
                ],
            ]),
        ];
    }

    /** Serve the wait script while PENDING, the full script once approved. */
    public function provisionRouter(array $payload): array
    {
        $routerId = $payload['routerId'] ?? $payload['router_id'] ?? null;
        if (! $routerId) {
            throw new \InvalidArgumentException('router_id is required');
        }
        $row = DB::table('mikrotiks')->where('router_id', $routerId)->first();
        if (! $row) {
            throw new \RuntimeException('Router not found');
        }
        $router = (array) $row;
        if (($router['setup_status'] ?? '') === 'pending') {
            return [
                'router' => $router,
                'assignedIp' => $router['wireguard_ip'] ?? null,
                'script' => self::generateWaitScript($router),
                'pending' => true,
            ];
        }
        $assignedIp = $router['wireguard_ip'] ?: $this->wireguard->getNextAvailableIp();
        $this->wireguard->addPeerToServer($routerId, (string) ($router['wireguard_public_key'] ?? ''), $assignedIp);
        DB::table('mikrotiks')->where('router_id', $routerId)->update([
            'host' => $assignedIp, 'status' => 'online', 'last_seen' => now(),
            'setup_status' => 'configured', 'setup_step' => 3,
            'ssid' => $payload['ssid'] ?? $router['ssid'] ?? self::defaultSsid(),
            'updated_at' => now(),
        ]);

        return [
            'router' => $router,
            'assignedIp' => $assignedIp,
            'serverPublicKey' => $this->wireguard->ensureServerKeys()['publicKey'],
            'serverPublicIp' => self::publicHost(),
            'wgPort' => $this->wireguard->listenPort,
            'wgNetwork' => $this->wireguard->networkCidr,
            'radiusSecret' => env('RADIUS_SECRET', ''),
            'ssid' => $router['ssid'] ?? self::defaultSsid(),
            'script' => self::generateProvisionScript([
                'router' => $router,
                'router_id' => $routerId,
                'router_name' => $router['router_name'] ?? $routerId,
                'wireguard_ip' => $assignedIp,
                'wireguard' => [
                    'routerPrivateKey' => $router['wireguard_private_key'] ?? '',
                    'serverPublicKey' => $this->wireguard->ensureServerKeys()['publicKey'],
                    'serverPublicIp' => self::publicHost(),
                    'serverIp' => $this->wireguard->serverIp,
                    'port' => $this->wireguard->listenPort,
                ],
            ]),
            'pending' => false,
        ];
    }

    /** Register a router discovered by a collector heartbeat (utils/collectorTunnel.js). */
    public function autoRegisterDetected(array $detected, string $collectorKey): ?string
    {
        $identity = (string) ($detected['identity'] ?? '');
        if (empty($detected['connected']) || $identity === '') {
            return null;
        }
        $routerId = 'router-'.preg_replace('/[^a-zA-Z0-9]/', '-', strtolower($identity));
        if (DB::table('mikrotiks')->where('router_id', $routerId)->exists()) {
            return null;
        }
        $now = now();
        DB::table('mikrotiks')->insert([
            'router_id' => $routerId,
            'router_name' => $identity,
            'host' => $detected['host'] ?? '',
            'port' => (int) ($detected['port'] ?? 8728),
            'user' => 'admin',
            'password' => '',
            'ssid' => '',
            'status' => 'offline',
            'setup_status' => 'detected',
            'model' => $detected['model'] ?? null,
            'firmware_version' => $detected['firmware'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $routerId;
    }

    /** Shared addRouter used by the wizard / quick-setup routes. */
    public function addRouter(array $spec): array
    {
        $routerId = $spec['routerID'] ?? $spec['router_id'] ?? null;
        $host = $spec['host'] ?? null;
        if (! $routerId || ! $host) {
            return ['success' => false, 'message' => 'Router ID and host are required'];
        }
        $data = [
            'router_name' => $spec['routerName'] ?? $spec['router_name'] ?? "Router-{$routerId}",
            'host' => $host,
            'port' => (int) ($spec['port'] ?? 8728),
            'user' => $spec['user'] ?? 'admin',
            'password' => self::encryptSecret((string) ($spec['password'] ?? 'admin')),
            'ssid' => $spec['ssid'] ?? self::defaultSsid(),
            'updated_at' => now(),
        ];
        $existing = DB::table('mikrotiks')->where('router_id', $routerId)->first();
        if ($existing) {
            DB::table('mikrotiks')->where('router_id', $routerId)->update($data);

            return ['success' => true, 'message' => 'Router updated', 'data' => ['routerID' => $routerId] + $data];
        }
        $now = now();
        DB::table('mikrotiks')->insert($data + [
            'router_id' => $routerId, 'status' => 'offline', 'setup_status' => 'pending',
            'setup_step' => 1, 'created_at' => $now,
        ]);

        return ['success' => true, 'message' => 'Router registered successfully',
            'data' => ['routerID' => $routerId] + $data];
    }

    public function row(string $routerId): ?array
    {
        $row = DB::table('mikrotiks')->where('router_id', $routerId)->first()
            ?: (is_numeric($routerId) ? DB::table('mikrotiks')->where('id', (int) $routerId)->first() : null);

        return $row ? (array) $row : null;
    }

    public function configureRouter(string $routerId, array $options): array
    {
        $row = $this->row($routerId);
        if (! $row) {
            return ['success' => false, 'message' => 'Router not found'];
        }
        if (empty($options['serverIP'])) {
            return ['success' => false, 'message' => 'Server IP is required for RADIUS configuration'];
        }
        try {
            $session = $this->manager->connect($row);
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Cannot connect to router: '.$e->getMessage()];
        }
        try {
            DB::table('mikrotiks')->where('id', $row['id'] ?? $routerId)->update([
                'setup_status' => 'configuring', 'setup_step' => 3, 'updated_at' => now(),
            ]);
            $report = $session->driver->configureHotspot($session, [
                'serverIP' => $options['serverIP'],
                'radiusSecret' => $options['radiusSecret'] ?? env('RADIUS_SECRET', ''),
                'hotspotInterface' => $options['hotspotInterface'] ?? 'wlan1',
                'hotspotIP' => $options['hotspotIP'] ?? env('HOTSPOT_IP', '10.5.50.1'),
                'hotspotNet' => $options['hotspotNet'] ?? env('HOTSPOT_SUBNET', '10.5.50.0'),
                'ssid' => $options['ssid'] ?? self::defaultSsid(),
                'routerName' => $options['routerName'] ?? $row['router_name'] ?? null,
            ]);
            DB::table('mikrotiks')->where('id', $row['id'] ?? $routerId)->update([
                'ssid' => $options['ssid'] ?? $row['ssid'] ?? self::defaultSsid(),
                'setup_status' => 'configured', 'setup_step' => 5, 'updated_at' => now(),
            ]);

            return ['success' => ! empty($report['success']), 'message' => $report['message'] ?? 'Configured',
                'config' => $report['config'] ?? [], 'executed' => $report['executed'] ?? [], 'failed' => $report['failed'] ?? []];
        } catch (CapabilityException $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'capability' => $e->capability];
        } finally {
            $session->driver->disconnect($session);
        }
    }

    public function verifyConfiguration(string $routerId): array
    {
        $row = $this->row($routerId);
        if (! $row) {
            return ['configured' => false, 'checks' => []];
        }
        try {
            $session = $this->manager->connect($row);
            if (method_exists($session->driver, 'verifyConfiguration')) {
                // Both drivers expose a read-only verification helper; never
                // call applyProvisioning() just to check status.
                $result = $session->driver->verifyConfiguration($session);
            } else {
                $result = ['configured' => false, 'checks' => []];
            }
            $session->driver->disconnect($session);
        } catch (\Throwable $e) {
            return ['configured' => false, 'message' => 'Router offline: '.$e->getMessage(),
                'checks' => ['hotspotServer' => 'unknown', 'dhcp' => 'unknown', 'radius' => 'unknown', 'wifi' => 'unknown']];
        }
        if (! empty($result['configured'])) {
            DB::table('mikrotiks')->where('id', $row['id'] ?? $routerId)->update([
                'setup_status' => 'configured', 'setup_step' => 6, 'status' => 'online',
                'setup_completed_at' => DB::raw('COALESCE(setup_completed_at, NOW())'), 'updated_at' => now(),
            ]);
        }

        return $result;
    }

    // ------------------------------------------------------------------
    // Script generation (byte-compatible with the RouterOS scripts in Node)
    // ------------------------------------------------------------------

    private static function sanitize(mixed $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], (string) ($value ?? ''));
    }

    private static function normalizeCidr(mixed $value): string
    {
        $raw = trim((string) ($value ?: ''));
        if ($raw === '') {
            return '10.5.50.0/24';
        }

        return str_contains($raw, '/') ? $raw : $raw.'/24';
    }

    public static function generateBootstrapScript(): string
    {
        $base = self::baseUrl();
        $secret = self::registrationSecret();
        $host = self::publicHost();
        $mode = self::fetchMode();

        return <<<RSC
# ============================================================================
# HotBando Zero-Touch Bootstrap — paste ONCE into WebFig Terminal
# Server: {$base}
# ============================================================================
:put "=== Cleaning old HotBando files / configs ==="
:do { /file remove [find name="universal-bootstrap.rsc"] } on-error={}
:do { /interface wireguard remove [find name="wg-hotbando"] } on-error={}
:do { /system scheduler remove [find name="hotbando-poll"] } on-error={}
:do { /system scheduler remove [find name="hotbando-heartbeat"] } on-error={}

:global hotbandoBaseUrl "{$base}"
:global hotbandoSecret "{$secret}"
:global hbSerial ""
:do { :set hbSerial [/system routerboard get serial-number] } on-error={
  :set hbSerial ("CHR-" . [/system clock get date] . "-" . [/system clock get time])
}
:global hbIdentity ""
:do { :set hbIdentity [/system identity get name] } on-error={ :set hbIdentity "MikroTik" }
:global hbModel ""
:do { :set hbModel [/system resource get board-name] } on-error={ :set hbModel "unknown" }
:global hbFirmware ""
:do { :set hbFirmware [/system resource get version] } on-error={ :set hbFirmware "unknown" }

:put ("Serial: " . \$hbSerial)
:put ("Identity: " . \$hbIdentity)
:put ("Model: " . \$hbModel)
:put ("Firmware: " . \$hbFirmware)

:global hbRegUrl ("{$base}/api/zero-touch/register?secret=" . \$hotbandoSecret . "&serial=" . \$hbSerial . "&auto_connect=1")
:global dlOk false
:global dlMode "{$mode}"
:for i from=1 to=3 do={
  :do { /tool fetch url=\$hbRegUrl dst-path="universal-bootstrap.rsc" mode=\$dlMode check-certificate=no } on-error={}
  :delay 3
  :if ([:len [/file find name="universal-bootstrap.rsc"]] > 0) do={ :set dlOk true }
}
:if (!\$dlOk) do={ :error "HotBando phone-home failed" }

:global fileSize 0
:do { :set fileSize [/file get [/file find name="universal-bootstrap.rsc"] size] } on-error={}
:if (\$fileSize < 100) do={ :error "HotBando phone-home failed (corrupt script)" }
:do { /import file-name="universal-bootstrap.rsc" } on-error={ :error "HotBando import failed" }
:do { /file remove "universal-bootstrap.rsc" } on-error={}
:put "HotBando setup complete! Router is provisioned."
# Verify tunnel
/ping {$host} count=3
RSC;
    }

    public static function generateWaitScript(array $router): string
    {
        $routerId = self::sanitize($router['router_id'] ?? '');
        $base = self::baseUrl();
        $secret = self::registrationSecret();
        $mode = self::fetchMode();
        $ip = $router['wireguard_ip'] ?? '';

        return <<<RSC
# HotBando Router — REGISTERED, awaiting CONNECT approval
# Router: {$routerId} — reserved WireGuard IP: {$ip}
# The owner must click CONNECT in the HotBando panel. This router polls every
# 2 minutes and pulls + applies the full config automatically.
:global hbRouterId "{$routerId}"
:global hbPollUrl ("{$base}/api/zero-touch/provision/" . \$hbRouterId . "?secret={$secret}")
:put ("HotBando registered: " . \$hbRouterId . " — waiting for CONNECT approval")

/system scheduler remove [find name="hotbando-poll"]
/system scheduler add name="hotbando-poll" interval=2m start-time=startup on-event={
  :local tmp "hb-latest.rsc"
  :do { /file remove \$tmp } on-error={}
  :do { /tool fetch url=\$hbPollUrl dst-path=\$tmp mode={$mode} } on-error={ :log warning "HotBando poll fetch failed" }
  :delay 3
  :local size 0
  :do { :set size [/file get [/file find name=\$tmp] size] } on-error={}
  :if (\$size > 300) do={
    :do { /import file-name=\$tmp } on-error={ :log warning "HotBando import failed" }
    :delay 2
    :do { /file remove \$tmp } on-error={}
  }
} comment="HotBando poll for approval"

/system scheduler remove [find name="hotbando-heartbeat"]
/system scheduler add name="hotbando-heartbeat" interval=2m start-time=startup on-event={
  :do { /tool fetch url=("{$base}/api/zero-touch/heartbeat?router_id={$routerId}&secret={$secret}") mode={$mode} keep-result=no check-certificate=yes } on-error={}
} comment="HotBando heartbeat"
:put "Polling for approval every 2 minutes..."
RSC;
    }

    /** Build the full RouterOS provisioning script (utils/routerProvision.js). */
    public static function generateProvisionScript(array $config): string
    {
        $router = $config['router'] ?? $config;
        $routerId = self::sanitize($config['router_id'] ?? ($router['router_id'] ?? 'router'));
        $routerName = self::sanitize($config['router_name'] ?? ($router['router_name'] ?? $routerId));
        $ssid = self::sanitize($config['ssid'] ?? ($router['ssid'] ?? self::defaultSsid()));
        $ip = self::sanitize($config['wireguard_ip'] ?? ($router['wireguard_ip'] ?? '10.7.0.2'));
        $wg = $config['wireguard'] ?? [];
        $wgPrivate = self::sanitize($wg['routerPrivateKey'] ?? ($router['wireguard_private_key'] ?? ''));
        $wgServerPub = self::sanitize($wg['serverPublicKey'] ?? '');
        $wgEndpoint = self::sanitize($wg['serverPublicIp'] ?? self::publicHost());
        $wgPort = (int) ($wg['port'] ?? env('WG_PORT', 51820));
        $wgServerIp = self::sanitize($wg['serverIp'] ?? env('WG_SERVER_IP', '10.7.0.1'));
        $hotspotIp = self::sanitize(env('HOTSPOT_IP', '10.5.50.1'));
        $cidr = self::normalizeCidr(env('HOTSPOT_SUBNET', '10.5.50.0'));
        [$base, $prefix] = array_pad(explode('/', $cidr, 2), 2, '24');
        $prefix3 = preg_replace('/\.\d+$/', '', $base);
        $dns = self::sanitize(env('HOTSPOT_DNS', '8.8.8.8'));
        $radiusSecret = self::sanitize($config['radiusSecret'] ?? env('RADIUS_SECRET', ''));
        $radiusHost = $wgServerIp;
        $baseUrl = self::baseUrl();
        $secret = self::registrationSecret();
        $mode = self::fetchMode();
        $generated = gmdate('c');
        $apiPassword = self::sanitize(env('MIKROTIK_API_PASSWORD', 'HotBandoApi2024!Secure'));
        $captive = implode("\n", array_map(fn ($h) => "/ip dns static add name=\"{$h}\" address={$hotspotIp} ttl=1m comment=\"HotBando captive\"", MikrotikDriver::captiveHosts()));
        $cdn = implode("\n", array_map(fn ($h) => "/ip hotspot walled-garden add action=allow dst-host=\"{$h}\" comment=\"HotBando portal\"", [
            'cdn.tailwindcss.com', 'fonts.googleapis.com', 'fonts.gstatic.com', 'cdnjs.cloudflare.com', 'unpkg.com', 'cdn.jsdelivr.net',
        ]));
        $walledHosts = '';
        $host = self::publicHost();
        if (! preg_match('/^\d{1,3}(?:\.\d{1,3}){3}$/', $host)) {
            $walledHosts = "/ip hotspot walled-garden add action=allow dst-host=\"{$host}\" comment=\"HotBando server\"\n"
                ."/ip hotspot walled-garden add action=allow dst-host=\"www.{$host}\" comment=\"HotBando server (www)\"";
        }
        $serverIp = (string) env('SERVER_PUBLIC_IP', '');
        $walledIp = preg_match('/^\d{1,3}(?:\.\d{1,3}){3}$/', $serverIp)
            ? "/ip hotspot walled-garden ip add action=accept dst-address={$serverIp} comment=\"HotBando server IP\""
            : '';

        return <<<RSC
# ============================================================================
# HotBando Router Auto-Provisioning Script (generated by server)
# Router: {$routerId} — {$routerName}
# WireGuard IP: {$ip}
# Generated: {$generated}
# ============================================================================
/system identity set name="{$routerName}"

:do { /user remove [find name="hotbando-api"] } on-error={}
:do { /user group remove [find name="hotbando-api"] } on-error={}
:do { /user group add name="hotbando-api" policy=read,api,rest-api skin=default } on-error={}
:do { /user add name="hotbando-api" group="hotbando-api" password="{$apiPassword}" comment="HotBando API" } on-error={}

# --- Interfaces: WAN ether1, hotspot bridge with all wireless radios ---
:global wanIface "ether1"
:global hotspotIface "bridge-hotbando"
:do { /interface bridge add name=bridge-hotbando disabled=no comment="HotBando hotspot bridge" } on-error={}
:global wirelessCount 0
:do {
  :foreach w in=[/interface wireless find] do={
    :do { /interface bridge port remove [find interface=\$w] } on-error={}
    :do { /interface bridge port add bridge=bridge-hotbando interface=\$w } on-error={}
    :do { /interface wireless set \$w ssid="{$ssid}" mode=ap-bridge disabled=no } on-error={}
    :set wirelessCount (\$wirelessCount + 1)
  }
} on-error={}
:do {
  :foreach w in=[/interface wifi find] do={
    :do { /interface bridge port remove [find interface=\$w] } on-error={}
    :do { /interface bridge port add bridge=bridge-hotbando interface=\$w } on-error={}
    :do { /interface wifi set \$w disabled=no ssid="{$ssid}" } on-error={
      :do { /interface wifi set \$w disabled=no configuration.ssid="{$ssid}" } on-error={}
    }
    :set wirelessCount (\$wirelessCount + 1)
  }
} on-error={}

# --- WireGuard tunnel ---
/interface wireguard remove [find name="wg-hotbando"]
/interface wireguard add name="wg-hotbando" listen-port=13231 private-key="{$wgPrivate}"
/interface wireguard peers remove [find interface="wg-hotbando"]
/interface wireguard peers add interface="wg-hotbando" public-key="{$wgServerPub}" endpoint-address={$wgEndpoint} endpoint-port={$wgPort} allowed-address={$wgServerIp}/32 persistent-keepalive=25s
/ip address add address={$ip}/32 interface="wg-hotbando"
/ip route add dst-address={$wgServerIp}/32 gateway="wg-hotbando"
/ip firewall filter remove [find comment="HotBando WG"]
/ip firewall filter add chain=input protocol=udp dst-port=13231 action=accept place-before=0 comment="HotBando WG"
/ip firewall filter add chain=input in-interface="wg-hotbando" action=accept place-before=0 comment="HotBando WG input"
/ip firewall filter add chain=forward in-interface="wg-hotbando" action=accept comment="HotBando WG"
/ip firewall filter add chain=forward out-interface="wg-hotbando" action=accept comment="HotBando WG"
:do { /interface list member add list=LAN interface="wg-hotbando" comment="HotBando WG LAN" } on-error={}
/ip service set api disabled=no address=10.7.0.0/16

# --- Hotspot network ---
/ip dhcp-server remove [find interface=\$hotspotIface]
/ip address remove [find interface=\$hotspotIface]
/ip address add address={$hotspotIp}/{$prefix} interface=\$hotspotIface network={$base} comment="HotBando hotspot gateway"
/ip pool remove [find name="hotbando-pool"]
/ip pool add name="hotbando-pool" ranges={$prefix3}.100-{$prefix3}.200 comment="HotBando client pool"
/ip dhcp-server remove [find name="hotbando-dhcp"]
/ip dhcp-server add name="hotbando-dhcp" interface=\$hotspotIface address-pool=hotbando-pool lease-time=1h disabled=no comment="HotBando DHCP"
/ip dhcp-server network remove [find address={$cidr}]
/ip dhcp-server network add address={$cidr} gateway={$hotspotIp} dns-server={$hotspotIp}
/ip dns set allow-remote-requests=yes servers={$dns}
/ip dns static remove [find comment="HotBando captive"]
{$captive}
/ip firewall nat remove [find comment="HotBando masquerade"]
/ip firewall nat add chain=srcnat src-address={$cidr} action=masquerade comment="HotBando masquerade"
/ip firewall nat remove [find comment="HotBando dns"]
/ip firewall nat add chain=dstnat protocol=udp dst-port=53 in-interface=\$hotspotIface action=redirect to-ports=53 comment="HotBando dns"
/ip firewall nat add chain=dstnat protocol=tcp dst-port=53 in-interface=\$hotspotIface action=redirect to-ports=53 comment="HotBando dns"
/ip firewall filter remove [find comment="HotBando DNS"]
/ip firewall filter add chain=input protocol=udp dst-port=53 in-interface=\$hotspotIface action=accept place-before=0 comment="HotBando DNS"
/ip firewall filter add chain=input protocol=tcp dst-port=53 in-interface=\$hotspotIface action=accept place-before=0 comment="HotBando DNS"

# --- Hotspot server, profile and walled garden ---
/ip hotspot remove [find]
/ip hotspot profile remove [find name="hotbando-profile"]
/ip hotspot profile add name="hotbando-profile" login-by=http-pap,http-chap,mac html-directory=hotspot use-radius=yes radius-accounting=yes radius-interim-update=30s radius-mac-format=XX:XX:XX:XX:XX:XX hotspot-address={$hotspotIp} dns-name="hotspot.hotbando.local" comment="HotBando hotspot profile"
:do { /ip hotspot profile set [find name="hotbando-profile"] mac-auth-mode=mac-as-username-and-password } on-error={}
/ip hotspot add name="hotbando-server" interface=\$hotspotIface address-pool=hotbando-pool profile=hotbando-profile disabled=no comment="HotBando hotspot server"
/ip hotspot walled-garden remove [find comment~"HotBando"]
/ip hotspot walled-garden ip remove [find comment~"HotBando"]
{$walledHosts}
{$walledIp}
/ip hotspot walled-garden ip add action=accept protocol=udp dst-port=53 comment="HotBando DNS"
/ip hotspot walled-garden ip add action=accept protocol=tcp dst-port=53 comment="HotBando DNS"
{$cdn}

# --- RADIUS ---
/radius remove [find comment~"HotBando"]
/radius remove [find service=hotspot]
/radius add address={$radiusHost} secret={$radiusSecret} service=hotspot authentication-port=1812 accounting-port=1813 timeout=3000ms src-address={$ip} comment="HotBando FreeRADIUS"
/radius incoming set accept=yes port=1700

# --- User profiles ---
/ip hotspot user profile remove [find comment~"HotBando"]
/ip hotspot user profile add name="hotbando-free" rate-limit="3M/3M 512K/512K 2000/2000" idle-timeout=30m session-timeout=2h comment="HotBando free"
/ip hotspot user profile add name="hotbando-paid" rate-limit="12M/12M 1M/1M 5000/5000" idle-timeout=1h session-timeout=24h comment="HotBando paid"
/ip hotspot user profile add name="hotbando-vip" rate-limit="50M/50M 5M/5M 10000/10000" idle-timeout=2h session-timeout=168h comment="HotBando VIP"
/ip hotspot user remove [find comment~"HotBando"]
/ip hotspot user add name="admin" password="hotbando2024" profile=hotbando-vip server=hotbando-server comment="HotBando admin"
/ip hotspot ip-binding remove [find comment~"HotBando"]

# --- Watchdog + heartbeat ---
/system scheduler remove [find name="hotbando-watchdog"]
/system scheduler add name="hotbando-watchdog" interval=2m on-event={
  :do { /ip hotspot set [find name="hotbando-server"] disabled=no } on-error={}
  :do { /radius enable [find service=hotspot] } on-error={}
  :do { /interface wireguard set [find name="wg-hotbando"] disabled=no } on-error={}
} comment="HotBando watchdog"
/system scheduler remove [find name="hotbando-poll"]
/system scheduler remove [find name="hotbando-heartbeat"]
/system scheduler add name="hotbando-heartbeat" interval=2m start-time=startup on-event={
  :do { /tool fetch url=("{$baseUrl}/api/zero-touch/heartbeat?router_id={$routerId}&secret={$secret}") mode={$mode} keep-result=no check-certificate=yes } on-error={}
} comment="HotBando heartbeat"

# --- Captive portal redirect page ---
:delay 1
/file remove hotspot/login.html
:local portalUrl "{$baseUrl}/hotspot?router_id={$routerId}&mac=\\$(mac)&ip=\\$(ip)"
:local loginHtml "<html><head><meta http-equiv='refresh' content='0;url=\$portalUrl'></head><body><a href='\$portalUrl'>Open HotBando</a></body></html>"
/file add name="hotspot/login.html" contents=\$loginHtml
:put "HotBando router provisioned successfully: {$routerId} ({$ip})"
RSC;
    }
}
