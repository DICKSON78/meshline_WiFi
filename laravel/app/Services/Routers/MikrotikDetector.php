<?php

namespace App\Services\Routers;

use Illuminate\Support\Facades\DB;

/**
 * Port of utils/mikrotikDetector.js: ARP/OUI discovery, optional CIDR port
 * scan, RouterOS API verification and default-credential recovery.
 */
class MikrotikDetector
{
    private const OUIS = [
        '4c:5e:0c', '6c:3b:6b', '74:4d:28', 'b8:69:f4', 'cc:2d:21', 'd4:01:c3',
        'e4:8d:8c', 'e8:bd:d1', '2c:c8:1b', '18:fd:74', '48:a9:8a', '64:d1:54',
    ];

    /** @var array<string,array> */
    private array $detected = [];

    public bool $isScanning = false;

    public function scanForMikroTik(?string $network = null): array
    {
        if ($this->isScanning) {
            return [];
        }
        $this->isScanning = true;
        try {
            $devices = array_merge($this->scanArpTable(), $this->scanDhcpLeases());
            if ($network && ($cidr = $this->sanitizeNetwork($network)) !== null) {
                $devices = array_merge($devices, $this->scanPorts($cidr));
            }
            $devices = array_merge($devices, $this->scanMdns());
            $enriched = $this->enrich($this->dedupe($devices));
            $this->detected = [];
            foreach ($enriched as $device) {
                $this->detected[$device['ip']] = $device;
            }

            return array_values($this->detected);
        } finally {
            $this->isScanning = false;
        }
    }

    private function sanitizeNetwork(?string $input): ?string
    {
        if (! $input || ! preg_match('/^(\d{1,3}\.){3}\d{1,3}(?:\/\d{1,2})?$/', $input)) {
            return null;
        }
        $parts = explode('/', $input)[0];
        foreach (explode('.', $parts) as $octet) {
            if ((int) $octet > 255) {
                return null;
            }
        }

        return $input;
    }

    private function scanArpTable(): array
    {
        $devices = [];
        $raw = @shell_exec('arp -a 2>/dev/null || ip neigh show 2>/dev/null') ?: '';
        foreach (explode("\n", $raw) as $line) {
            if (preg_match('/(\d+\.\d+\.\d+\.\d+)\s+\S*\s*([0-9a-fA-F:]{17})/', $line, $m)
                && $this->isMikrotikMac($m[2])) {
                $devices[] = ['ip' => $m[1], 'mac' => $m[2], 'source' => 'arp', 'isMikrotik' => true, 'confidence' => 'high'];
            }
        }
        // Optional host-mounted neighbour table (workstation stack).
        foreach (['/host-arp', base_path('../data/host-arp.txt')] as $file) {
            if (! is_readable($file)) {
                continue;
            }
            foreach (explode("\n", (string) file_get_contents($file)) as $line) {
                $tokens = preg_split('/\s+/', trim($line)) ?: [];
                if (! $tokens || ! preg_match('/^(\d{1,3}\.){3}\d{1,3}$/', $tokens[0])) {
                    continue;
                }
                foreach ($tokens as $token) {
                    if (preg_match('/^([0-9a-fA-F]{2}:){5}[0-9a-fA-F]{2}$/', $token) && $this->isMikrotikMac($token)) {
                        $devices[] = ['ip' => $tokens[0], 'mac' => $token, 'source' => 'arp',
                            'isMikrotik' => true, 'confidence' => 'high'];
                        break;
                    }
                }
            }
        }

        return $devices;
    }

    private function scanDhcpLeases(): array
    {
        $devices = [];
        $raw = @shell_exec('cat /var/lib/dhcp/dhclient.leases 2>/dev/null | grep -E "lease|fixed-address|hardware" || true') ?: '';
        $lease = [];
        foreach (explode("\n", $raw) as $line) {
            if (str_contains($line, 'lease {')) {
                $lease = [];
            } elseif (preg_match('/fixed-address\s+([\d.]+)/', $line, $m)) {
                $lease['ip'] = $m[1];
            } elseif (preg_match('/hardware ethernet\s+([0-9a-fA-F:]+)/', $line, $m)) {
                $lease['mac'] = $m[1];
            } elseif (str_contains($line, '}') && isset($lease['ip'], $lease['mac'])) {
                $devices[] = $lease + ['source' => 'dhcp', 'isMikrotik' => false, 'confidence' => 'medium'];
                $lease = [];
            }
        }

        return $devices;
    }

    /** Bounded TCP scan of a /24 (or smaller) network. */
    private function scanPorts(string $cidr): array
    {
        $devices = [];
        [$base, $prefix] = array_pad(explode('/', $cidr, 2), 2, '24');
        $prefix = max(24, min(32, (int) $prefix));
        if ($prefix < 24) {
            return [];
        }
        $parts = explode('.', $base);
        if (count($parts) !== 4) {
            return [];
        }
        $last = (int) $parts[3];
        $start = $prefix === 32 ? $last : max(1, $last);
        $end = $prefix === 32 ? $last : 254;
        for ($i = $start; $i <= $end; $i++) {
            $ip = "{$parts[0]}.{$parts[1]}.{$parts[2]}.{$i}";
            if (! $this->tcpOpen($ip, 8728, 250)) {
                continue;
            }
            $verification = $this->verifyMikrotik($ip, 8728, 'admin', '');
            if (! empty($verification['isMikrotik'])) {
                $devices[] = [
                    'ip' => $ip, 'mac' => null, 'source' => 'port', 'isMikrotik' => true,
                    'confidence' => 'high', 'identity' => $verification['identity'] ?? null,
                    'model' => $verification['model'] ?? null, 'firmware' => $verification['firmware'] ?? null,
                ];
            }
        }

        return $devices;
    }

    private function scanMdns(): array
    {
        $devices = [];
        $raw = @shell_exec('avahi-browse -a -t 2>/dev/null | grep -i mikrotik || true') ?: '';
        foreach (explode("\n", $raw) as $line) {
            if (preg_match('/(\d{1,3}(?:\.\d{1,3}){3})/', $line, $m)) {
                $devices[] = ['ip' => $m[1], 'mac' => null, 'source' => 'mdns',
                    'isMikrotik' => true, 'confidence' => 'high'];
            }
        }

        return $devices;
    }

    private function dedupe(array $devices): array
    {
        $seen = [];
        foreach ($devices as $device) {
            $ip = $device['ip'] ?? '';
            if ($ip === '' || (($device['confidence'] ?? '') !== 'high' && isset($seen[$ip]))) {
                continue;
            }
            $seen[$ip] = $device;
        }

        return array_values($seen);
    }

    private function enrich(array $devices): array
    {
        $out = [];
        foreach ($devices as $device) {
            if (empty($device['isMikrotik']) || ($device['confidence'] ?? '') !== 'high') {
                $verification = $this->verifyMikrotik((string) ($device['ip'] ?? ''));
                if (empty($verification['isMikrotik'])) {
                    continue;
                }
                $device['isMikrotik'] = true;
                foreach (['identity', 'model', 'firmware', 'uptime'] as $key) {
                    $device[$key] ??= $verification[$key] ?? null;
                }
            }
            try {
                $existing = DB::table('mikrotiks')->where('host', $device['ip'])
                    ->first(['router_id', 'router_name', 'setup_status']);
                $device['inDatabase'] = $existing !== null;
                $device['existingRouter'] = $existing ? (array) $existing : null;
            } catch (\Throwable $e) {
                $device['inDatabase'] = false;
                $device['existingRouter'] = null;
            }
            $out[] = $device;
        }

        return $out;
    }

    public function verifyMikrotik(string $ip, int $port = 8728, string $user = 'admin', string $password = ''): array
    {
        $client = new RouterOsClient([
            'host' => $ip, 'port' => $port, 'user' => $user, 'password' => $password, 'timeout' => 3,
        ]);
        try {
            $client->connect();
            $identity = $client->firstRow('/system/identity');
            $resource = $client->firstRow('/system/resource');
            $client->close();

            return [
                'isMikrotik' => true,
                'identity' => $identity['name'] ?? null,
                'model' => $resource['board-name'] ?? null,
                'firmware' => $resource['version'] ?? null,
                'uptime' => $resource['uptime'] ?? null,
            ];
        } catch (\Throwable $e) {
            $client->close();
            $message = strtolower($e->getMessage());
            $authFailed = (bool) preg_match('/login|password|not enough|permission|auth/', $message);

            return ['isMikrotik' => $authFailed, 'authFailed' => $authFailed];
        }
    }

    public function findWorkingCredentials(string $ip, int $port = 8728): ?array
    {
        foreach ([['admin', ''], ['admin', 'admin'], ['admin', '1234'], ['admin', 'root']] as [$user, $password]) {
            $result = $this->verifyMikrotik($ip, $port, $user, $password);
            if (! empty($result['isMikrotik']) && empty($result['authFailed'])) {
                return ['user' => $user, 'password' => $password] + $result;
            }
        }

        return null;
    }

    public static function tcpOpen(string $ip, int $port, int $ms = 1500): bool
    {
        $socket = @stream_socket_client("tcp://{$ip}:{$port}", $errno, $errstr, max(0.05, $ms / 1000));
        if (! $socket) {
            return false;
        }
        fclose($socket);

        return true;
    }

    public function getDetected(): array
    {
        return array_values($this->detected);
    }

    public function getByIp(string $ip): ?array
    {
        return $this->detected[$ip] ?? null;
    }

    public function getNetworkInfo(): array
    {
        $raw = @shell_exec('ip -4 addr show 2>/dev/null | grep -E "inet " | grep -v "127.0.0.1"') ?: '';
        $interfaces = [];
        foreach (explode("\n", $raw) as $line) {
            if (! preg_match('/inet\s+([\d.]+)\/(\d+)\s+.*\s+(\S+)/', $line, $m)) {
                continue;
            }
            $parts = explode('.', $m[1]);
            $network = implode('.', array_slice($parts, 0, 3)).'.0';
            $interfaces[] = [
                'interface' => $m[3], 'ip' => $m[1], 'cidr' => (int) $m[2], 'network' => $network,
                'range' => "{$parts[0]}.{$parts[1]}.{$parts[2]}.1-254",
            ];
        }

        return $interfaces;
    }

    private function isMikrotikMac(string $mac): bool
    {
        $mac = strtolower($mac);
        foreach (self::OUIS as $oui) {
            if (str_starts_with($mac, $oui)) {
                return true;
            }
        }

        return false;
    }
}
