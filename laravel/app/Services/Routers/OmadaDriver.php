<?php

namespace App\Services\Routers;

/**
 * One Omada driver for on-prem controllers and the cloud portal.
 *
 * Operations that are RouterOS-specific throw CapabilityException instead of
 * pretending success.  Everything else maps to Omada OpenAPI primitives and
 * degrades gracefully when a firmware version does not expose an endpoint.
 */
class OmadaDriver implements RouterDriver
{
    private const CAPABILITIES = [
        'hotspot', 'portal', 'vouchers', 'clients', 'radius', 'ssid', 'logs',
        'reboot', 'ssh', 'walled-garden',
    ];

    public function name(): string
    {
        return 'omada';
    }

    public function supports(string $capability): bool
    {
        $capability = strtolower($capability);

        if (in_array($capability, ['raw', 'script', 'fetch', 'wireguard', 'file', 'ping'], true)) {
            return false;
        }

        return in_array($capability, self::CAPABILITIES, true);
    }

    public function connect(array $router): RouterSession
    {
        $client = new OmadaClient($router);
        $client->authenticate();

        return new RouterSession($this, $router, $client, ['mode' => $client->mode(), 'site' => $client->siteId()]);
    }

    public function disconnect(RouterSession $session): void
    {
        $session->connection = null;
    }

    public function identity(RouterSession $session): array
    {
        $devices = $this->client($session)->devices();
        $first = $devices[0] ?? [];
        $uptime = (int) ($first['uptime'] ?? 0);

        return [
            'name' => $first['name'] ?? ($session->router['router_name'] ?? 'Omada controller'),
            'identity' => $first['name'] ?? ($session->router['router_name'] ?? 'Omada controller'),
            'model' => $first['model'] ?? $first['modelName'] ?? 'Omada',
            'firmware' => $first['firmwareVersion'] ?? $first['version'] ?? 'unknown',
            'version' => $first['firmwareVersion'] ?? $first['version'] ?? 'unknown',
            'serial' => $first['serialNumber'] ?? '',
            'uptime' => $uptime > 0 ? gmdate('H:i:s', $uptime) : '0s',
            'cpu_load' => (int) ($first['cpuUsage'] ?? 0),
            'cpu' => (int) ($first['cpuUsage'] ?? 0),
            'memory_usage' => (int) ($first['memoryUsage'] ?? 0),
            'memory' => (int) ($first['memoryUsage'] ?? 0),
            'devices' => count($devices),
        ];
    }

    public function interfaces(RouterSession $session): array
    {
        $rows = [];
        foreach ($this->client($session)->devices() as $device) {
            $id = (string) ($device['id'] ?? $device['deviceId'] ?? '');
            foreach ($id !== '' ? $this->client($session)->ports($id) : [] as $port) {
                $rows[] = [
                    'name' => $port['name'] ?? ($port['portIndex'] ?? 'port').'',
                    '.id' => $port['id'] ?? $port['portId'] ?? '',
                    'type' => $port['type'] ?? 'ether',
                    'running' => ! empty($port['up']) || ($port['status'] ?? '') === 'up',
                    'disabled' => empty($port['enabled']) ? 'true' : 'false',
                    'device' => $device['name'] ?? $id,
                ];
            }
        }

        return $rows;
    }

    public function setSsid(RouterSession $session, string $interface, string $ssid): bool
    {
        foreach ($this->client($session)->wlans() as $wlan) {
            $id = (string) ($wlan['id'] ?? $wlan['wlanId'] ?? '');
            $name = (string) ($wlan['name'] ?? $wlan['ssid'] ?? '');
            if ($id !== '' && ($name === $interface || $id === $interface || $interface === '')) {
                return $this->client($session)->updateSsid($id, $ssid);
            }
        }

        return false;
    }

    /** Create/configure the portal, voucher template, SSID and RADIUS. */
    public function configureHotspot(RouterSession $session, array $config): array
    {
        $executed = [];
        $failed = [];
        $client = $this->client($session);
        $ssid = (string) ($config['ssid'] ?? env('DEFAULT_SSID', 'HotBando-Free-WiFi'));

        $wlans = $client->wlans();
        if ($wlans) {
            $first = $wlans[0];
            $id = (string) ($first['id'] ?? $first['wlanId'] ?? '');
            if ($id !== '' && $client->updateSsid($id, $ssid)) {
                $executed[] = 'Configure SSID';
            } else {
                $failed[] = ['label' => 'Configure SSID', 'error' => 'Omada rejected the WLAN update'];
            }
        } else {
            $failed[] = ['label' => 'Configure SSID', 'error' => 'No WLAN found'];
        }

        $portalName = $config['portalName'] ?? 'HotBando';
        $portal = null;
        foreach ($client->portals() as $existing) {
            if (($existing['name'] ?? '') === $portalName) {
                $portal = $existing;
                break;
            }
        }
        if (! $portal) {
            $portal = $client->createPortal(array_filter([
                'name' => $portalName,
                'portalType' => 'external',
                'redirectUrl' => $config['portalUrl'] ?? $this->publicBase(),
            ], fn ($v) => $v !== null));
        }
        if ($portal) {
            $executed[] = $client->portals() ? 'Verify portal' : 'Create portal';
        } else {
            $failed[] = ['label' => 'Create portal', 'error' => 'Portal endpoint unavailable on this controller'];
        }

        if (! empty($config['radiusSecret'])) {
            if ($client->configureRadius([
                'name' => 'HotBando',
                'server' => $config['serverIP'] ?? $this->publicHost(),
                'port' => 1812,
                'secret' => $config['radiusSecret'],
            ])) {
                $executed[] = 'Configure RADIUS';
            } else {
                $failed[] = ['label' => 'Configure RADIUS', 'error' => 'AAA endpoint unavailable on this controller'];
            }
        }

        return [
            'driver' => 'omada',
            'success' => $executed !== [],
            'message' => $executed !== [] ? 'Omada hotspot configured (partial)' : 'Omada configuration failed',
            'config' => ['ssid' => $ssid, 'portal' => $portal, 'mode' => $client->mode()],
            'executed' => $executed,
            'failed' => $failed,
        ];
    }

    public function configureRadius(RouterSession $session, array $config): bool
    {
        return $this->client($session)->configureRadius([
            'name' => 'HotBando',
            'server' => $config['serverIP'] ?? $config['address'] ?? $this->publicHost(),
            'port' => (int) ($config['port'] ?? 1812),
            'secret' => (string) ($config['radiusSecret'] ?? $config['secret'] ?? env('RADIUS_SECRET', '')),
        ]);
    }

    public function setWalledGarden(RouterSession $session, array $hosts, array $ips): bool
    {
        return $this->client($session)->walledGarden([
            'domains' => $hosts,
            'ips' => $ips,
            'action' => 'allow',
        ]);
    }

    /** Omada grants access by creating a voucher (or a RADIUS identity). */
    public function grantAccess(RouterSession $session, string $clientKey, array $limits = [], bool $preserveBytes = false): array
    {
        $duration = (int) ($limits['uptime_seconds'] ?? $limits['uptime'] ?? 3600);
        $voucher = $this->client($session)->createVoucher([
            'name' => $clientKey,
            'note' => $clientKey,
            'clientKey' => $clientKey,
            'duration' => $duration,
            'limitBytes' => (int) ($limits['bytes'] ?? $limits['limit_bytes_total'] ?? 0),
            'quota' => 1,
        ]);

        return [
            'success' => $voucher !== [],
            'user' => $clientKey,
            'username' => $clientKey,
            'voucher' => $voucher,
            'code' => $voucher['code'] ?? $voucher['voucherCode'] ?? $clientKey,
        ];
    }

    public function revokeAccess(RouterSession $session, string $clientKey): bool
    {
        $client = $this->client($session);
        $ok = false;
        foreach ($client->clients() as $row) {
            $identity = (string) ($row['mac'] ?? $row['clientKey'] ?? $row['name'] ?? '');
            if (strcasecmp($identity, $clientKey) === 0) {
                $id = (string) ($row['id'] ?? $row['clientId'] ?? '');
                if ($id !== '') {
                    $ok = $client->setClientBlocked($id, true) || $ok;
                    $ok = $client->disconnectClient($id) || $ok;
                }
            }
        }
        foreach ($client->vouchers() as $voucher) {
            if (($voucher['name'] ?? $voucher['note'] ?? '') === $clientKey && ! empty($voucher['id'])) {
                $ok = $client->deleteVoucher((string) $voucher['id']) || $ok;
            }
        }

        return $ok;
    }

    public function activeSessions(RouterSession $session, ?string $clientKey = null): array
    {
        $rows = [];
        foreach ($this->client($session)->clients() as $row) {
            $mac = (string) ($row['mac'] ?? $row['clientKey'] ?? '');
            if ($clientKey !== null && strcasecmp($mac, $clientKey) !== 0) {
                continue;
            }
            $rows[] = [
                '.id' => $row['id'] ?? $row['clientId'] ?? '',
                'user' => $row['name'] ?? $row['username'] ?? $mac,
                'mac-address' => $mac,
                'address' => $row['ip'] ?? $row['ipAddress'] ?? '',
                'uptime' => $row['uptime'] ?? '',
                'bytes-in' => $row['rxBytes'] ?? 0,
                'bytes-out' => $row['txBytes'] ?? 0,
                'radius' => true,
            ];
        }

        return $rows;
    }

    public function kickSession(RouterSession $session, string $sessionId): bool
    {
        return $this->client($session)->disconnectClient($sessionId);
    }

    public function hotspotUsers(RouterSession $session): array
    {
        return $this->client($session)->vouchers();
    }

    public function createVoucher(RouterSession $session, array $spec): array
    {
        return $this->client($session)->createVoucher($spec);
    }

    public function reboot(RouterSession $session): bool
    {
        foreach ($this->client($session)->devices() as $device) {
            $id = (string) ($device['id'] ?? $device['deviceId'] ?? '');
            if ($id !== '') {
                return $this->client($session)->rebootDevice($id);
            }
        }

        return false;
    }

    public function logs(RouterSession $session, int $limit = 50): array
    {
        return $this->client($session)->logs($limit);
    }

    public function applyProvisioning(RouterSession $session, array $config): array
    {
        $report = $this->configureHotspot($session, $config);
        $report['unsupported'] = [];

        // WireGuard is a RouterOS feature — report it as skipped instead of
        // silently claiming success.
        if (! empty($config['wireguard'])) {
            try {
                $this->provisionWireGuard($session, $config['wireguard']);
            } catch (CapabilityException $e) {
                $report['unsupported'][] = $e->capability;
                $report['failed'][] = ['label' => 'WireGuard', 'error' => $e->getMessage()];
            }
        }
        try {
            $this->provisionScripts($session);
        } catch (CapabilityException $e) {
            $report['unsupported'][] = $e->capability;
        }

        return $report;
    }

    public function bootstrapInstructions(array $config): string
    {
        $ssid = $config['ssid'] ?? env('DEFAULT_SSID', 'HotBando-Free-WiFi');
        $secret = ProvisioningService::registrationSecret();

        return implode("\n", [
            'HotBando Omada setup checklist (no RouterOS .rsc script is available)',
            '-------------------------------------------------------------',
            '1. Log in to the Omada controller: '.($config['host'] ?? 'https://<controller>:8043').'',
            '2. Create/verify a site and note its Site ID (or set OMADA_SITE_ID).',
            "3. Adopt the access point(s) and create SSID \"{$ssid}\" with WPA2/WPA3.",
            '4. Create an External Portal pointing to '.$this->publicBase().'/hotspot.',
            '5. Add the portal FQDN and CDN hosts to the portal whitelist / walled garden.',
            '6. Configure AAA/RADIUS: server '.$this->publicHost().', port 1812/1813, secret '.$secret.'.',
            '7. Enable client isolation where appropriate and set per-client bandwidth limits.',
            '8. Set CONTROLLER_MODE=omada and OMADA_MODE=onprem (or cloud) on this router.',
            '9. Use the dashboard to verify active clients; reboot the AP from the dashboard if needed.',
            'Note: WireGuard, /tool fetch, /import and raw RouterOS commands are not supported on Omada.',
        ]);
    }

    public function fetchAndImportScript(RouterSession $session, string $url, string $filename): bool
    {
        throw new CapabilityException('fetchAndImportScript', $this->name());
    }

    public function rawCommand(RouterSession $session, string $path, array $args = []): array
    {
        throw new CapabilityException('rawCommand', $this->name());
    }

    /** Extra primitives used by admin endpoints; explicitly unsupported. */
    public function wireGuardPeers(RouterSession $session): array
    {
        throw new CapabilityException('wireguard', $this->name());
    }

    public function ping(RouterSession $session, string $host, int $count = 3): array
    {
        throw new CapabilityException('ping', $this->name());
    }

    public function ipBindings(RouterSession $session): array
    {
        return [];
    }

    public function hotspotServers(RouterSession $session): array
    {
        return $this->client($session)->portals();
    }

    public function toggleHotspotServer(RouterSession $session, string $name, bool $enable): bool
    {
        return false;
    }

    public function toggleInterface(RouterSession $session, string $name, bool $enable): bool
    {
        return false;
    }

    public function userProfiles(RouterSession $session): array
    {
        return [];
    }

    public function queues(RouterSession $session): array
    {
        return [];
    }

    public function addQueue(RouterSession $session, array $spec): bool
    {
        return false;
    }

    public function systemResource(RouterSession $session): array
    {
        return $this->identity($session);
    }

    public function routerBoard(RouterSession $session): array
    {
        return $this->identity($session);
    }

    public function setIdentity(RouterSession $session, string $name): bool
    {
        return false;
    }

    public function fullStatus(RouterSession $session): array
    {
        $info = $this->identity($session);

        return [
            'routerID' => $session->routerId(),
            'identity' => $info['name'],
            'isOnline' => true,
            'uptime' => $info['uptime'],
            'version' => $info['version'],
            'cpu' => $info['cpu_load'],
            'memory' => $info['memory_usage'],
            'activeUsers' => count($this->activeSessions($session)),
            'interfaces' => count($this->interfaces($session)),
            'totalInterfaces' => count($this->interfaces($session)),
            'platform' => 'Omada SDN',
            'boardName' => $info['model'],
            'driver' => 'omada',
            'lastChecked' => gmdate('c'),
        ];
    }

    public function verifyConfiguration(RouterSession $session): array
    {
        $portals = $this->client($session)->portals();
        $wlans = $this->client($session)->wlans();
        $radius = $this->client($session)->radiusServers();

        $checks = [
            'hotspotServer' => $portals ? 'ok' : 'not_configured',
            'dhcp' => 'ok', // managed by the Omada controller
            'radius' => $radius ? 'ok' : 'not_configured',
            'wifi' => $wlans ? 'ok' : 'not_configured',
        ];

        return ['configured' => $checks['hotspotServer'] === 'ok' && $checks['wifi'] === 'ok', 'checks' => $checks];
    }

    private function provisionWireGuard(RouterSession $session, array $config): void
    {
        throw new CapabilityException('wireguard provisioning', $this->name());
    }

    private function provisionScripts(RouterSession $session): void
    {
        throw new CapabilityException('system scripts', $this->name());
    }

    private function client(RouterSession $session): OmadaClient
    {
        if (! $session->connection instanceof OmadaClient) {
            $session->connection = new OmadaClient($session->router);
        }
        $session->connection->authenticate();

        return $session->connection;
    }

    private function publicBase(): string
    {
        return rtrim((string) (env('PUBLIC_BASE_URL') ?: env('APP_URL') ?: 'https://'.$this->publicHost()), '/');
    }

    private function publicHost(): string
    {
        return (string) (env('SERVER_PUBLIC_HOST') ?: env('SERVER_PUBLIC_IP') ?: 'hotbandowifi.online');
    }
}
