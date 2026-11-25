<?php

namespace App\Services\Routers;

/**
 * MikroTik RouterOS driver.  Every primitive used by the Node backend is
 * available here (see utils/mikrotik.js, utils/routerManager.js,
 * utils/routerProvision.js), either through the binary API or the REST port.
 */
class MikrotikDriver implements RouterDriver
{
    private const CAPABILITIES = [
        'raw', 'script', 'fetch', 'wireguard', 'ping', 'scheduler', 'radius',
        'hotspot', 'portal', 'vouchers', 'queue', 'firewall', 'reboot', 'logs',
    ];

    public function name(): string
    {
        return 'mikrotik';
    }

    public function supports(string $capability): bool
    {
        return in_array(strtolower($capability), self::CAPABILITIES, true);
    }

    public function connect(array $router): RouterSession
    {
        $client = new RouterOsClient($router);
        $client->connect();

        return new RouterSession($this, $router, $client, ['mode' => $client->isRest() ? 'rest' : 'api']);
    }

    public function disconnect(RouterSession $session): void
    {
        $this->client($session)->close();
    }

    public function identity(RouterSession $session): array
    {
        $identity = $this->client($session)->firstRow('/system/identity');
        $resource = $this->client($session)->firstRow('/system/resource');
        $board = [];
        try {
            $board = $this->client($session)->firstRow('/system/routerboard');
        } catch (\Throwable $e) {
            // CHR / emulators do not implement /system/routerboard
        }
        $total = (int) ($resource['total-memory'] ?? 0);
        $free = (int) ($resource['free-memory'] ?? 0);
        $memory = $total > 0 ? (int) round((($total - $free) / $total) * 100) : 0;

        return [
            'name' => $identity['name'] ?? 'Unknown',
            'identity' => $identity['name'] ?? 'Unknown',
            'model' => $board['board-name'] ?? $resource['board-name'] ?? 'Unknown',
            'boardName' => $board['board-name'] ?? $resource['board-name'] ?? null,
            'firmware' => $resource['version'] ?? 'Unknown',
            'version' => $resource['version'] ?? 'Unknown',
            'serial' => $board['serial-number'] ?? $board['serial_number'] ?? '',
            'uptime' => $resource['uptime'] ?? '0s',
            'cpu_load' => (int) ($resource['cpu-load'] ?? 0),
            'cpu' => (int) ($resource['cpu-load'] ?? 0),
            'memory_usage' => $memory,
            'memory' => $memory,
            'freeMemory' => $free,
            'totalMemory' => $total,
            'architectureName' => $board['architecture-name'] ?? $resource['architecture-name'] ?? null,
        ];
    }

    public function interfaces(RouterSession $session): array
    {
        return $this->safeRows($session, '/interface');
    }

    public function setSsid(RouterSession $session, string $interface, string $ssid): bool
    {
        $client = $this->client($session);
        foreach (['/interface/wireless', '/interface/wifi'] as $menu) {
            try {
                $rows = $client->printRows($menu);
            } catch (\Throwable $e) {
                continue;
            }
            foreach ($rows as $row) {
                if (($row['.id'] ?? null) !== $interface && ($row['name'] ?? null) !== $interface) {
                    continue;
                }
                try {
                    if ($menu === '/interface/wireless') {
                        $client->command($menu.'/set', [
                            '.id' => $row['.id'] ?? $interface,
                            'ssid' => $ssid,
                            'mode' => 'ap-bridge',
                            'disabled' => 'no',
                        ]);
                    } else {
                        try {
                            $client->command($menu.'/set', [
                                '.id' => $row['.id'] ?? $interface,
                                'ssid' => $ssid,
                                'disabled' => 'no',
                            ]);
                        } catch (\Throwable $e) {
                            // RouterOS 7 uses configuration.ssid on some builds
                            $client->command($menu.'/set', [
                                '.id' => $row['.id'] ?? $interface,
                                'configuration.ssid' => $ssid,
                                'disabled' => 'no',
                            ]);
                        }
                    }

                    return true;
                } catch (\Throwable $e) {
                    return false;
                }
            }
        }

        return false;
    }

    /** Port of utils/routerManager.js configureRouter(). */
    public function configureHotspot(RouterSession $session, array $config): array
    {
        $client = $this->client($session);
        $serverIP = (string) ($config['serverIP'] ?? '');
        $radiusSecret = (string) ($config['radiusSecret'] ?? env('RADIUS_SECRET', ''));
        $hotspotInterface = (string) ($config['hotspotInterface'] ?? env('HOTSPOT_INTERFACE', 'wlan1'));
        $hotspotIP = (string) ($config['hotspotIP'] ?? env('HOTSPOT_IP', '10.5.50.1'));
        $hotspotNet = (string) ($config['hotspotNet'] ?? env('HOTSPOT_SUBNET', '10.5.50.0'));
        $ssid = (string) ($config['ssid'] ?? env('DEFAULT_SSID', 'HotBando-Free-WiFi'));
        $hotspotCidr = str_contains($hotspotNet, '/') ? $hotspotNet : $hotspotNet.'/24';
        [$netBase, $prefixLen] = array_pad(explode('/', $hotspotCidr, 2), 2, '24');
        $netPrefix = preg_replace('/\.\d+$/', '', $netBase);

        $executed = [];
        $failed = [];
        $run = function (string $label, string $path, array $args = []) use ($client, &$executed, &$failed): void {
            try {
                $client->command($path, $args);
                $executed[] = $label;
            } catch (\Throwable $e) {
                $failed[] = ['label' => $label, 'error' => $e->getMessage()];
            }
        };

        // A hotspot on a bridge *port* is bypassed by the bridge — put it on
        // the bridge that owns the radio instead.
        $iface = $hotspotInterface;
        try {
            foreach ($client->printRows('/interface/bridge/port') as $port) {
                if (($port['interface'] ?? '') === $hotspotInterface && ! empty($port['bridge'])) {
                    $iface = $port['bridge'];
                    break;
                }
            }
        } catch (\Throwable $e) {
            // bridge package not available
        }

        if (! empty($config['routerName'])) {
            $run('Set identity', '/system/identity/set', ['name' => $config['routerName']]);
        }
        $client->removeByComment('/ip/address', 'hotbando-hotspot');
        $run('Add hotspot IP address', '/ip/address/add', [
            'address' => "{$hotspotIP}/{$prefixLen}", 'interface' => $iface, 'comment' => 'hotbando-hotspot',
        ]);
        $run('Add DHCP pool', '/ip/pool/add', ['name' => 'hotbando-pool', 'ranges' => "{$netPrefix}.100-{$netPrefix}.200"]);
        $run('Add DHCP network', '/ip/dhcp-server/network/add', [
            'address' => $hotspotCidr, 'gateway' => $hotspotIP, 'dns-server' => $hotspotIP,
        ]);
        $run('Add DHCP server', '/ip/dhcp-server/add', [
            'name' => 'hotbando-dhcp', 'interface' => $iface, 'address-pool' => 'hotbando-pool', 'disabled' => 'no',
        ]);
        $run('Set DNS servers', '/ip/dns/set', ['servers' => env('HOTSPOT_DNS', '1.1.1.1,8.8.8.8'), 'allow-remote-requests' => 'yes']);

        // Captive-portal probes must be intercepted, never whitelisted.
        $client->removeByComment('/ip/dns/static', 'HotBando captive');
        foreach (self::captiveHosts() as $host) {
            $run("Add captive DNS {$host}", '/ip/dns/static/add', [
                'name' => $host, 'address' => $hotspotIP, 'ttl' => '1m', 'comment' => 'HotBando captive',
            ]);
        }
        $client->removeByComment('/ip/firewall/nat', 'HotBando dns');
        foreach (['udp', 'tcp'] as $proto) {
            $run("Redirect DNS ($proto)", '/ip/firewall/nat/add', [
                'chain' => 'dstnat', 'protocol' => $proto, 'dst-port' => '53',
                'in-interface' => $iface, 'action' => 'redirect', 'to-ports' => '53', 'comment' => 'HotBando dns',
            ]);
            $run("Accept DNS ($proto)", '/ip/firewall/filter/add', [
                'chain' => 'input', 'protocol' => $proto, 'dst-port' => '53',
                'in-interface' => $iface, 'action' => 'accept', 'place-before' => '0', 'comment' => 'HotBando DNS',
            ]);
        }

        $run('Add hotspot server', '/ip/hotspot/add', [
            'name' => 'hotspot1', 'interface' => $iface, 'address-pool' => 'hotbando-pool',
            'addresses-per-mac' => '1', 'disabled' => 'no',
        ]);
        $profile = $client->firstRow('/ip/hotspot/profile');
        if (isset($profile['.id'])) {
            $run('Enable hotspot RADIUS in profile', '/ip/hotspot/profile/set', [
                '.id' => $profile['.id'], 'use-radius' => 'yes', 'html-directory' => 'hotspot',
                'login-by' => 'http-pap,http-chap,mac', 'mac-auth-mode' => 'mac-as-username-and-password',
            ]);
        }
        foreach ([
            ['hotbando-free', '3M/3M 512K/512K 2000/2000', '30m', '2h'],
            ['hotbando-paid', '12M/12M 1M/1M 5000/5000', '1h', '24h'],
            ['hotbando-vip', '50M/50M 5M/5M 10000/10000', '2h', '168h'],
        ] as [$name, $rate, $idle, $session]) {
            try {
                $existing = $client->printRows('/ip/hotspot/user/profile', ['name' => $name]);
                if (! $existing) {
                    $client->command('/ip/hotspot/user/profile/add', [
                        'name' => $name, 'rate-limit' => $rate, 'idle-timeout' => $idle,
                        'session-timeout' => $session, 'comment' => 'HotBando '.substr($name, 9),
                    ]);
                }
            } catch (\Throwable $e) {
                // older RouterOS builds may lack this menu
            }
        }

        $run('Add NAT masquerade', '/ip/firewall/nat/add', [
            'chain' => 'srcnat', 'src-address' => $hotspotCidr, 'action' => 'masquerade',
        ]);
        $this->setWalledGarden($session, self::portalHosts(), self::portalIps());
        if ($serverIP !== '' && $radiusSecret !== '') {
            $this->configureRadius($session, [
                'serverIP' => $serverIP, 'radiusSecret' => $radiusSecret, 'routerIp' => '0.0.0.0',
            ]);
        }
        if (! $this->setSsid($session, $hotspotInterface, $ssid)) {
            $failed[] = ['label' => 'Set wireless SSID', 'error' => 'No matching wireless/wifi interface'];
        }

        return [
            'success' => $failed === [] || $executed !== [],
            'message' => $failed === [] ? 'Router configured successfully' : 'Configured with '.count($failed).' warning(s)',
            'config' => [
                'serverIP' => $serverIP,
                'radiusSecretConfigured' => $serverIP !== '' && $radiusSecret !== '',
                'hotspotInterface' => $iface, 'hotspotIP' => $hotspotIP, 'hotspotNet' => $hotspotNet, 'ssid' => $ssid,
            ],
            'executed' => $executed,
            'failed' => $failed,
        ];
    }

    public function configureRadius(RouterSession $session, array $config): bool
    {
        $client = $this->client($session);
        $address = (string) ($config['serverIP'] ?? $config['address'] ?? '');
        $secret = (string) ($config['radiusSecret'] ?? $config['secret'] ?? env('RADIUS_SECRET', ''));
        if ($address === '' || $secret === '') {
            return false;
        }
        try {
            $client->removeByComment('/radius', 'HotBando FreeRADIUS');
            $client->command('/radius/add', [
                'service' => 'hotspot', 'address' => $address, 'secret' => $secret,
                'src-address' => $config['routerIp'] ?? '0.0.0.0', 'timeout' => '3000ms',
                'authentication-port' => '1812', 'accounting-port' => '1813',
                'comment' => 'HotBando FreeRADIUS',
            ]);
            $client->command('/radius/incoming/set', ['accept' => 'yes', 'port' => '1700']);
            $client->setByQuery('/ip/hotspot/profile', ['name' => 'hotbando-profile'], ['use-radius' => 'yes']);
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }

    public function setWalledGarden(RouterSession $session, array $hosts, array $ips): bool
    {
        $client = $this->client($session);
        try {
            foreach (['/ip/hotspot/walled-garden', '/ip/hotspot/walled-garden/ip'] as $menu) {
                foreach ($client->printRows($menu) as $row) {
                    if (str_starts_with((string) ($row['comment'] ?? ''), 'HotBando')) {
                        $client->command($menu.'/remove', ['.id' => $row['.id']]);
                    }
                }
            }
            foreach ($hosts as $host) {
                if ($host === '') {
                    continue;
                }
                $client->command('/ip/hotspot/walled-garden/add', [
                    'action' => 'allow', 'dst-host' => $host, 'comment' => 'HotBando portal',
                ]);
            }
            foreach ($ips as $ip) {
                if ($ip === '') {
                    continue;
                }
                $client->command('/ip/hotspot/walled-garden/ip/add', [
                    'action' => 'accept', 'dst-address' => $ip, 'comment' => 'HotBando portal',
                ]);
            }
            foreach (['udp', 'tcp'] as $proto) {
                $client->command('/ip/hotspot/walled-garden/ip/add', [
                    'protocol' => $proto, 'dst-port' => '53', 'action' => 'accept', 'comment' => 'HotBando DNS',
                ]);
            }
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }

    /** MAC-as-username hotspot user, mirroring _addUserToRouterImpl(). */
    public function grantAccess(RouterSession $session, string $clientKey, array $limits = [], bool $preserveBytes = false): array
    {
        $client = $this->client($session);
        $mac = strtoupper(str_replace('-', ':', $clientKey));
        $seconds = (int) ($limits['uptime_seconds'] ?? $limits['uptime'] ?? 0);
        $bytes = (int) ($limits['bytes'] ?? $limits['limit_bytes_total'] ?? 0);
        $uptime = $seconds > 0 ? self::formatTime($seconds) : '0s';
        $profile = $seconds > 0 ? 'hotbando-paid' : 'hotbando-free';
        try {
            $profiles = $client->printRows('/ip/hotspot/user/profile');
            if (! array_filter($profiles, fn ($p) => ($p['name'] ?? '') === $profile)) {
                $profile = 'default';
            }
        } catch (\Throwable $e) {
            $profile = 'default';
        }

        // Existing routers often have http-pap only; MAC login would fail.
        $this->ensureMacLogin($session);

        $existing = $client->printRows('/ip/hotspot/user', ['mac-address' => $mac]);
        if (! $existing) {
            $existing = $client->printRows('/ip/hotspot/user', ['name' => $mac]);
        }
        $limitArgs = ['limit-uptime' => $uptime];
        if (! $preserveBytes || ! $existing) {
            $limitArgs['limit-bytes-total'] = $bytes > 0 ? (string) $bytes : '0';
            $limitArgs['limit-bytes-in'] = '0';
            $limitArgs['limit-bytes-out'] = '0';
        }
        try {
            if ($existing) {
                $client->command('/ip/hotspot/user/set', array_merge([
                    '.id' => $existing[0]['.id'] ?? '',
                    'name' => $mac, 'password' => $mac, 'mac-address' => $mac, 'profile' => $profile,
                ], $limitArgs));
            } else {
                $servers = $this->safeRows($session, '/ip/hotspot');
                $server = null;
                foreach (['hotbando-server', 'hotspot1'] as $wanted) {
                    foreach ($servers as $s) {
                        if (($s['name'] ?? '') === $wanted) {
                            $server = $s['name'];
                        }
                    }
                }
                if (! $server && isset($servers[0]['name'])) {
                    $server = $servers[0]['name'];
                }
                $args = [
                    'name' => $mac, 'password' => $mac, 'mac-address' => $mac, 'profile' => $profile,
                ] + $limitArgs;
                if ($server) {
                    $args['server'] = $server;
                }
                $client->command('/ip/hotspot/user/add', $args);
            }
            // Drop active + host entries so the next packet re-authenticates
            // with the new limits (Node does the same in addUserToRouter).
            $this->refreshClient($session, $mac);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'user' => $mac];
        }

        return [
            'success' => true,
            'user' => $mac,
            'username' => $mac,
            'profile' => $profile,
            'limits' => $limitArgs,
        ];
    }

    public function revokeAccess(RouterSession $session, string $clientKey): bool
    {
        $client = $this->client($session);
        $mac = strtoupper(str_replace('-', ':', $clientKey));
        $ok = true;
        foreach ([
            ['/ip/hotspot/active', 'mac-address'], ['/ip/hotspot/host', 'mac-address'], ['/ip/hotspot/user', 'mac-address'],
        ] as [$menu, $field]) {
            try {
                foreach ($client->printRows($menu, [$field => $mac]) as $row) {
                    if (isset($row['.id'])) {
                        $client->command($menu.'/remove', ['.id' => $row['.id']]);
                    }
                }
            } catch (\Throwable $e) {
                $ok = false;
            }
        }
        // Legacy user rows sometimes only carry the MAC as the name.
        try {
            foreach ($client->printRows('/ip/hotspot/user', ['name' => $mac]) as $row) {
                if (isset($row['.id'])) {
                    $client->command('/ip/hotspot/user/remove', ['.id' => $row['.id']]);
                }
            }
        } catch (\Throwable $e) {
            $ok = false;
        }

        return $ok;
    }

    public function activeSessions(RouterSession $session, ?string $clientKey = null): array
    {
        $query = $clientKey !== null
            ? ['mac-address' => strtoupper(str_replace('-', ':', $clientKey))]
            : [];

        return $this->safeRows($session, '/ip/hotspot/active', $query);
    }

    public function kickSession(RouterSession $session, string $sessionId): bool
    {
        try {
            $this->client($session)->command('/ip/hotspot/active/remove', ['.id' => $sessionId]);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function hotspotUsers(RouterSession $session): array
    {
        return $this->safeRows($session, '/ip/hotspot/user');
    }

    public function createVoucher(RouterSession $session, array $spec): array
    {
        $args = [];
        foreach ($spec as $key => $value) {
            if ($value !== null && $value !== '' && ! is_array($value)) {
                $args[$key] = $value;
            }
        }
        $args['comment'] ??= 'HotBando voucher';
        $this->client($session)->command('/ip/hotspot/user/add', $args);

        return ['success' => true, 'voucher' => $args, 'name' => $args['name'] ?? ''];
    }

    public function reboot(RouterSession $session): bool
    {
        try {
            $this->client($session)->command('/system/reboot');

            return true;
        } catch (\Throwable $e) {
            // The socket is often dropped before the reply arrives.
            return str_contains(strtolower($e->getMessage()), 'socket closed')
                || str_contains(strtolower($e->getMessage()), 'timed out');
        }
    }

    public function logs(RouterSession $session, int $limit = 50): array
    {
        $rows = $this->safeRows($session, '/log');

        return array_slice($rows, -max(1, $limit));
    }

    public function applyProvisioning(RouterSession $session, array $config): array
    {
        $client = $this->client($session);
        $executed = [];
        $failed = [];
        $run = function (string $label, string $path, array $args = []) use ($client, &$executed, &$failed): void {
            try {
                $client->command($path, $args);
                $executed[] = $label;
            } catch (\Throwable $e) {
                $failed[] = ['label' => $label, 'error' => $e->getMessage()];
            }
        };

        $ssid = (string) ($config['ssid'] ?? env('DEFAULT_SSID', 'HotBando-Free-WiFi'));
        $routerId = (string) ($config['router_id'] ?? $session->routerId());
        $hotspotIp = (string) ($config['hotspot_ip'] ?? env('HOTSPOT_IP', '10.5.50.1'));
        $hotspotNet = (string) ($config['hotspot_net'] ?? env('HOTSPOT_SUBNET', '10.5.50.0'));
        $cidr = str_contains($hotspotNet, '/') ? $hotspotNet : $hotspotNet.'/24';
        [$base, $prefix] = array_pad(explode('/', $cidr, 2), 2, '24');
        $prefix3 = preg_replace('/\.\d+$/', '', $base);

        $run('Set identity', '/system/identity/set', ['name' => $config['router_name'] ?? $routerId]);
        $bridge = 'bridge-hotbando';
        $bridges = $this->safeRows($session, '/interface/bridge');
        if (! array_filter($bridges, fn ($b) => ($b['name'] ?? '') === $bridge)) {
            $run('Create hotspot bridge', '/interface/bridge/add', ['name' => $bridge, 'disabled' => 'no', 'comment' => 'HotBando hotspot bridge']);
        }
        foreach (['/interface/wireless', '/interface/wifi'] as $menu) {
            foreach ($this->safeRows($session, $menu) as $radio) {
                if (! isset($radio['.id'])) {
                    continue;
                }
                $run("Bridge radio {$menu}", '/interface/bridge/port/add', ['bridge' => $bridge, 'interface' => $radio['name'] ?? $radio['.id']]);
                if ($menu === '/interface/wireless') {
                    $run("SSID on {$menu}", $menu.'/set', ['.id' => $radio['.id'], 'ssid' => $ssid, 'mode' => 'ap-bridge', 'disabled' => 'no']);
                } else {
                    $run("SSID on {$menu}", $menu.'/set', ['.id' => $radio['.id'], 'ssid' => $ssid, 'disabled' => 'no']);
                }
            }
        }

        // WireGuard tunnel (only when the server supplied keys).
        if (! empty($config['wireguard'])) {
            $wg = $config['wireguard'];
            foreach ($this->safeRows($session, '/interface/wireguard', ['name' => 'wg-hotbando']) as $old) {
                if (isset($old['.id'])) {
                    $run('Remove old WG interface', '/interface/wireguard/remove', ['.id' => $old['.id']]);
                }
            }
            $run('Add WG interface', '/interface/wireguard/add', [
                'name' => 'wg-hotbando', 'listen-port' => '13231', 'private-key' => $wg['routerPrivateKey'] ?? '',
            ]);
            $run('Add WG peer', '/interface/wireguard/peers/add', [
                'interface' => 'wg-hotbando', 'public-key' => $wg['serverPublicKey'] ?? '',
                'endpoint-address' => $wg['serverPublicIp'] ?? $this->serverHost(),
                'endpoint-port' => (string) ($wg['port'] ?? env('WG_PORT', '51820')),
                'allowed-address' => ($wg['serverIp'] ?? env('WG_SERVER_IP', '10.7.0.1')).'/32',
                'persistent-keepalive' => '25s',
            ]);
            $run('Add WG address', '/ip/address/add', ['address' => ($config['wireguard_ip'] ?? '').'/32', 'interface' => 'wg-hotbando']);
            $run('Add WG route', '/ip/route/add', [
                'dst-address' => ($wg['serverIp'] ?? env('WG_SERVER_IP', '10.7.0.1')).'/32', 'gateway' => 'wg-hotbando',
            ]);
            $run('Allow WG input', '/ip/firewall/filter/add', [
                'chain' => 'input', 'protocol' => 'udp', 'dst-port' => '13231', 'action' => 'accept', 'comment' => 'HotBando WG',
            ]);
        }

        // Hotspot network + portal services.
        foreach ($this->safeRows($session, '/ip/address', ['interface' => $bridge]) as $row) {
            if (isset($row['.id'])) {
                $run('Clear hotspot address', '/ip/address/remove', ['.id' => $row['.id']]);
            }
        }
        $run('Hotspot address', '/ip/address/add', [
            'address' => "{$hotspotIp}/{$prefix}", 'interface' => $bridge, 'comment' => 'HotBando hotspot gateway',
        ]);
        $run('DHCP pool', '/ip/pool/add', ['name' => 'hotbando-pool', 'ranges' => "{$prefix3}.100-{$prefix3}.200"]);
        $run('DHCP server', '/ip/dhcp-server/add', [
            'name' => 'hotbando-dhcp', 'interface' => $bridge, 'address-pool' => 'hotbando-pool', 'disabled' => 'no',
        ]);
        $run('DHCP network', '/ip/dhcp-server/network/add', [
            'address' => $cidr, 'gateway' => $hotspotIp, 'dns-server' => $hotspotIp,
        ]);
        $run('DNS', '/ip/dns/set', ['allow-remote-requests' => 'yes', 'servers' => env('HOTSPOT_DNS', '8.8.8.8')]);
        foreach (self::captiveHosts() as $host) {
            $run("Captive DNS {$host}", '/ip/dns/static/add', [
                'name' => $host, 'address' => $hotspotIp, 'ttl' => '1m', 'comment' => 'HotBando captive',
            ]);
        }
        $run('Masquerade', '/ip/firewall/nat/add', ['chain' => 'srcnat', 'src-address' => $cidr, 'action' => 'masquerade']);
        foreach (['udp', 'tcp'] as $proto) {
            $run("DNS redirect ($proto)", '/ip/firewall/nat/add', [
                'chain' => 'dstnat', 'protocol' => $proto, 'dst-port' => '53',
                'in-interface' => $bridge, 'action' => 'redirect', 'to-ports' => '53', 'comment' => 'HotBando dns',
            ]);
        }
        foreach ($this->safeRows($session, '/ip/hotspot') as $row) {
            if (isset($row['.id'])) {
                $run('Remove old hotspot', '/ip/hotspot/remove', ['.id' => $row['.id']]);
            }
        }
        $run('Hotspot server', '/ip/hotspot/add', [
            'name' => 'hotbando-server', 'interface' => $bridge, 'address-pool' => 'hotbando-pool',
            'profile' => 'hotbando-profile', 'disabled' => 'no',
        ]);
        $run('Hotspot profile', '/ip/hotspot/profile/add', [
            'name' => 'hotbando-profile', 'login-by' => 'http-pap,http-chap,mac',
            'use-radius' => 'yes', 'radius-accounting' => 'yes', 'hotspot-address' => $hotspotIp,
        ]);
        $this->setWalledGarden($session, self::portalHosts(), self::portalIps());
        if (! empty($config['radiusSecret'])) {
            $this->configureRadius($session, [
                'serverIP' => $config['radiusServerIp'] ?? $this->serverHost(),
                'radiusSecret' => $config['radiusSecret'],
                'routerIp' => $config['wireguard_ip'] ?? '0.0.0.0',
            ]);
        }
        foreach ([
            ['hotbando-free', '3M/3M 512K/512K 2000/2000', '30m', '2h'],
            ['hotbando-paid', '12M/12M 1M/1M 5000/5000', '1h', '24h'],
            ['hotbando-vip', '50M/50M 5M/5M 10000/10000', '2h', '168h'],
        ] as [$name, $rate, $idle, $timeout]) {
            $run("Profile $name", '/ip/hotspot/user/profile/add', [
                'name' => $name, 'rate-limit' => $rate, 'idle-timeout' => $idle, 'session-timeout' => $timeout,
            ]);
        }
        // Heartbeat scheduler + poll script removal.
        foreach ($this->safeRows($session, '/system/scheduler', ['name' => 'hotbando-poll']) as $row) {
            if (isset($row['.id'])) {
                $run('Remove approval poller', '/system/scheduler/remove', ['.id' => $row['.id']]);
            }
        }
        $base = rtrim((string) (env('PUBLIC_BASE_URL') ?: env('APP_URL') ?: 'https://'.$this->serverHost()), '/');
        $secret = ProvisioningService::registrationSecret();
        $onEvent = ':do { /tool fetch url="'.$base.'/api/zero-touch/heartbeat?router_id='.$routerId.'&secret='.$secret.'" mode=https keep-result=no check-certificate=yes } on-error={}';
        $run('Heartbeat scheduler', '/system/scheduler/add', [
            'name' => 'hotbando-heartbeat', 'interval' => '2m', 'start-time' => 'startup',
            'on-event' => $onEvent, 'comment' => 'HotBando heartbeat',
        ]);

        return [
            'driver' => 'mikrotik', 'success' => $failed === [],
            'executed' => $executed, 'failed' => $failed,
            'unsupported' => [],
        ];
    }

    public function bootstrapInstructions(array $config): string
    {
        if (class_exists(ProvisioningService::class)) {
            return ProvisioningService::generateProvisionScript($config);
        }

        return "# HotBando provisioning script for ".($config['router_id'] ?? 'router')."\n";
    }

    public function fetchAndImportScript(RouterSession $session, string $url, string $filename): bool
    {
        $client = $this->client($session);
        try {
            foreach ($client->printRows('/file', ['name' => $filename]) as $file) {
                if (isset($file['.id'])) {
                    $client->command('/file/remove', ['.id' => $file['.id']]);
                }
            }
            $client->command('/tool/fetch', [
                'url' => $url, 'dst-path' => $filename, 'check-certificate' => 'no',
            ]);
            $files = $client->printRows('/file', ['name' => $filename]);
            if (! $files) {
                return false;
            }
            if ((int) ($files[0]['size'] ?? 0) < 300) {
                return false;
            }
            $client->command('/import', ['file-name' => $filename]);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function rawCommand(RouterSession $session, string $path, array $args = []): array
    {
        return $this->client($session)->command($path, $args);
    }

    // ------------------------------------------------------------------
    // Extra primitives used by the legacy admin endpoints
    // ------------------------------------------------------------------

    public function testConnection(array $router): array
    {
        try {
            $session = $this->connect($router);
            $info = $this->identity($session);
            $this->disconnect($session);

            return ['success' => true, 'message' => 'Connection successful', 'data' => $info];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'data' => null];
        }
    }

    public function verifyConfiguration(RouterSession $session): array
    {
        $checks = ['hotspotServer' => 'unknown', 'dhcp' => 'unknown', 'radius' => 'unknown', 'wifi' => 'unknown'];
        try {
            $hotspots = $this->safeRows($session, '/ip/hotspot');
            $checks['hotspotServer'] = array_filter($hotspots, fn ($h) => ($h['disabled'] ?? 'true') === 'false') ? 'ok' : 'not_configured';
            $checks['dhcp'] = $this->safeRows($session, '/ip/dhcp-server') ? 'ok' : 'not_configured';
            $radius = $this->safeRows($session, '/radius');
            $checks['radius'] = array_filter($radius, fn ($r) => ($r['service'] ?? '') === 'hotspot') ? 'ok' : 'not_configured';
            $wireless = array_merge($this->safeRows($session, '/interface/wireless'), $this->safeRows($session, '/interface/wifi'));
            $checks['wifi'] = array_filter($wireless, fn ($w) => ($w['disabled'] ?? 'true') === 'false') ? 'ok' : 'not_configured';
        } catch (\Throwable $e) {
            // keep unknown states
        }

        return ['configured' => $checks['hotspotServer'] === 'ok' && $checks['dhcp'] === 'ok', 'checks' => $checks];
    }

    public function fullStatus(RouterSession $session): array
    {
        $info = $this->identity($session);
        $sessions = $this->activeSessions($session);
        $interfaces = $this->interfaces($session);

        return [
            'routerID' => $session->routerId(),
            'identity' => $info['name'],
            'isOnline' => true,
            'uptime' => $info['uptime'],
            'version' => $info['version'],
            'cpu' => $info['cpu_load'],
            'memory' => $info['memory_usage'],
            'activeUsers' => count($sessions),
            'interfaces' => count(array_filter($interfaces, fn ($i) => ($i['disabled'] ?? 'false') === 'false')),
            'totalInterfaces' => count($interfaces),
            'platform' => $info['boardName'] ?? 'Unknown',
            'boardName' => $info['boardName'] ?? 'Unknown',
            'lastChecked' => gmdate('c'),
        ];
    }

    public function ping(RouterSession $session, string $host, int $count = 3): array
    {
        return $this->safeRows($session, '/ping', ['address' => $host, 'count' => (string) $count]);
    }

    public function wireGuardPeers(RouterSession $session): array
    {
        return $this->safeRows($session, '/interface/wireguard/peers');
    }

    public function applyRouterScript(RouterSession $session, string $source, string $name = 'hotbando-repair'): bool
    {
        $client = $this->client($session);
        try {
            foreach ($client->printRows('/system/script', ['name' => $name]) as $row) {
                if (isset($row['.id'])) {
                    $client->command('/system/script/remove', ['.id' => $row['.id']]);
                }
            }
            $client->command('/system/script/add', ['name' => $name, 'source' => $source]);
            $script = $client->firstRow('/system/script', ['name' => $name]);
            if (! isset($script['.id'])) {
                return false;
            }
            $client->command('/system/script/run', ['.id' => $script['.id']]);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function resolveClientMac(RouterSession $session, string $ip): ?string
    {
        foreach ([
            ['/ip/dhcp-server/lease', 'address'], ['/ip/hotspot/active', 'address'], ['/ip/arp', 'address'],
        ] as [$menu, $field]) {
            foreach ($this->safeRows($session, $menu, [$field => $ip]) as $row) {
                if (! empty($row['mac-address'])) {
                    return strtoupper(str_replace('-', ':', (string) $row['mac-address']));
                }
            }
        }

        return null;
    }

    public function ipBindings(RouterSession $session): array
    {
        return $this->safeRows($session, '/ip/hotspot/ip-binding');
    }

    public function addIpBinding(RouterSession $session, array $spec): bool
    {
        $args = [];
        foreach (['mac' => 'mac-address', 'ip' => 'address', 'type' => 'type', 'comment' => 'comment'] as $from => $to) {
            if (isset($spec[$from]) && $spec[$from] !== '') {
                $args[$to] = $spec[$from];
            }
        }
        try {
            $this->client($session)->command('/ip/hotspot/ip-binding/add', $args);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function removeIpBinding(RouterSession $session, array $spec): bool
    {
        try {
            $client = $this->client($session);
            if (! empty($spec['bindingId'])) {
                $client->command('/ip/hotspot/ip-binding/remove', ['.id' => $spec['bindingId']]);

                return true;
            }
            $query = [];
            if (! empty($spec['mac'])) {
                $query['mac-address'] = $spec['mac'];
            }
            if (! empty($spec['ip'])) {
                $query['address'] = $spec['ip'];
            }
            $rows = $client->printRows('/ip/hotspot/ip-binding', $query);
            foreach ($rows as $row) {
                if (isset($row['.id'])) {
                    $client->command('/ip/hotspot/ip-binding/remove', ['.id' => $row['.id']]);
                }
            }

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function hotspotServers(RouterSession $session): array
    {
        return $this->safeRows($session, '/ip/hotspot');
    }

    public function toggleHotspotServer(RouterSession $session, string $name, bool $enable): bool
    {
        $rows = $this->client($session)->printRows('/ip/hotspot', ['name' => $name]);
        if (! isset($rows[0]['.id'])) {
            return false;
        }
        try {
            $this->client($session)->command('/ip/hotspot/'.($enable ? 'enable' : 'disable'), ['.id' => $rows[0]['.id']]);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function toggleInterface(RouterSession $session, string $name, bool $enable): bool
    {
        $rows = $this->client($session)->printRows('/interface', ['name' => $name]);
        if (! isset($rows[0]['.id'])) {
            return false;
        }
        try {
            $this->client($session)->command('/interface/'.($enable ? 'enable' : 'disable'), ['.id' => $rows[0]['.id']]);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function userProfiles(RouterSession $session): array
    {
        return $this->safeRows($session, '/ip/hotspot/user/profile');
    }

    public function queues(RouterSession $session): array
    {
        return $this->safeRows($session, '/queue/simple');
    }

    public function addQueue(RouterSession $session, array $spec): bool
    {
        try {
            $this->client($session)->command('/queue/simple/add', $spec);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function systemResource(RouterSession $session): array
    {
        return $this->client($session)->firstRow('/system/resource');
    }

    public function routerBoard(RouterSession $session): array
    {
        return $this->client($session)->firstRow('/system/routerboard');
    }

    public function setIdentity(RouterSession $session, string $name): bool
    {
        try {
            $this->client($session)->command('/system/identity/set', ['name' => $name]);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    private function client(RouterSession $session): RouterOsClient
    {
        if (! $session->connection instanceof RouterOsClient) {
            $session->connection = new RouterOsClient($session->router);
        }
        $session->connection->connect();

        return $session->connection;
    }

    private function safeRows(RouterSession $session, string $menu, array $query = []): array
    {
        try {
            return $this->client($session)->printRows($menu, $query);
        } catch (\Throwable $e) {
            // !empty, unsupported menus and offline routers are all non-fatal.
            return [];
        }
    }

    private function ensureMacLogin(RouterSession $session): void
    {
        $client = $this->client($session);
        try {
            foreach ($client->printRows('/ip/hotspot/profile') as $profile) {
                $name = (string) ($profile['name'] ?? '');
                if ($name !== 'default' && ! preg_match('/^(hotbando|hb-)/i', $name)) {
                    continue;
                }
                $loginBy = array_filter(explode(',', (string) ($profile['login-by'] ?? '')), 'strlen');
                if (! in_array('mac', $loginBy, true)) {
                    $loginBy[] = 'mac';
                }
                $args = ['.id' => $profile['.id'] ?? '', 'login-by' => implode(',', $loginBy)];
                if (($profile['mac-auth-mode'] ?? '') !== 'mac-as-username-and-password') {
                    $args['mac-auth-mode'] = 'mac-as-username-and-password';
                }
                try {
                    $client->command('/ip/hotspot/profile/set', $args);
                } catch (\Throwable $e) {
                    // older RouterOS builds lack mac-auth-mode
                }
            }
        } catch (\Throwable $e) {
            // profile menu unavailable
        }
    }

    private function refreshClient(RouterSession $session, string $mac): void
    {
        $client = $this->client($session);
        foreach ([
            ['/ip/hotspot/active', 'mac-address'], ['/ip/hotspot/active', 'user'], ['/ip/hotspot/host', 'mac-address'],
        ] as [$menu, $field]) {
            try {
                foreach ($client->printRows($menu, [$field => $mac]) as $row) {
                    if (isset($row['.id'])) {
                        $client->command($menu.'/remove', ['.id' => $row['.id']]);
                    }
                }
            } catch (\Throwable $e) {
                // non-fatal
            }
        }
    }

    public static function formatTime(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0s';
        }
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        $out = '';
        if ($h > 0) {
            $out .= "{$h}h";
        }
        if ($m > 0) {
            $out .= "{$m}m";
        }
        if ($s > 0) {
            $out .= "{$s}s";
        }

        return $out !== '' ? $out : '1s';
    }

    /** @return array<int,string> */
    public static function captiveHosts(): array
    {
        return [
            'connectivitycheck.gstatic.com', 'connectivitycheck.android.com', 'captive.apple.com',
            'msftconnecttest.com', 'www.msftconnecttest.com', 'msftncsi.com', 'www.msftncsi.com',
            'detectportal.firefox.com', 'nmcheck.gnome.org',
        ];
    }

    /** @return array<int,string> */
    public static function portalHosts(): array
    {
        $host = self::serverHost();
        $hosts = [$host];
        if (! str_starts_with(strtolower($host), 'www.')) {
            $hosts[] = 'www.'.$host;
        }
        foreach (['cdn.tailwindcss.com', 'fonts.googleapis.com', 'fonts.gstatic.com',
            'cdnjs.cloudflare.com', 'unpkg.com', 'cdn.jsdelivr.net'] as $cdn) {
            $hosts[] = $cdn;
        }

        return array_values(array_unique($hosts));
    }

    /** @return array<int,string> */
    public static function portalIps(): array
    {
        $ip = (string) (env('SERVER_PUBLIC_IP') ?: '');
        $ips = preg_match('/^\d{1,3}(?:\.\d{1,3}){3}$/', $ip) ? [$ip] : [];

        return $ips;
    }

    private static function serverHost(): string
    {
        return (string) (env('SERVER_PUBLIC_HOST') ?: env('SERVER_PUBLIC_IP') ?: 'hotbandowifi.online');
    }
}
