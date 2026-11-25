<?php

namespace App\Services\Routers;

use Illuminate\Support\Facades\DB;

/**
 * Port of utils/wireguard.js.  The Meshline WiFi server is the WireGuard hub; the
 * app only publishes peer descriptors for a host-side watcher and never needs
 * the server private key (except when explicitly mounted).
 */
class WireGuardService
{
    public string $interfaceName;

    public int $listenPort;

    public string $networkCidr;

    public string $serverIp;

    public string $serverPublicIp;

    private string $hostDir;

    private string $peersDir;

    private string $statusFile;

    public function __construct()
    {
        $this->interfaceName = (string) env('WG_INTERFACE', 'wg0');
        $this->listenPort = (int) env('WG_PORT', 51820);
        $this->networkCidr = (string) env('WG_NETWORK', '10.7.0.0/16');
        $this->serverIp = (string) env('WG_SERVER_IP', '10.7.0.1');
        $this->serverPublicIp = (string) (env('SERVER_PUBLIC_HOST') ?: env('SERVER_PUBLIC_IP') ?: 'hotbandowifi.online');
        $this->hostDir = (string) (env('WG_HOST_DIR') ?: (is_dir('/host-wireguard') ? '/host-wireguard' : '/etc/wireguard'));
        $this->peersDir = (string) (env('WG_PEERS_DIR') ?: (is_dir('/host-wireguard-peers')
            ? '/host-wireguard-peers'
            : $this->hostDir.'/meshline-peers'));
        $this->statusFile = (string) (env('WG_STATUS_FILE') ?: (is_file('/var/run/meshline/wg-status-result')
            ? '/var/run/meshline/wg-status-result'
            : $this->hostDir.'/.wg-status-result'));
    }

    /** @return array{privateKey:string,publicKey:string} */
    public function generateKeyPair(): array
    {
        $keypair = sodium_crypto_box_keypair();

        // Standard base64 (with padding) is what RouterOS /interface wireguard
        // and `wg` both expect — same as Node's buffer.toString('base64').
        return [
            'privateKey' => base64_encode(substr(sodium_crypto_box_secretkey($keypair), 0, 32)),
            'publicKey' => base64_encode(sodium_crypto_box_publickey($keypair)),
        ];
    }

    public function generatePresharedKey(): string
    {
        return base64_encode(random_bytes(32));
    }

    /** @return array{publicKey:string,privateKey:?string} */
    public function ensureServerKeys(): array
    {
        $configured = trim((string) env('WG_SERVER_PUBLIC_KEY', ''));
        if ($configured !== '') {
            return ['publicKey' => $configured, 'privateKey' => null];
        }
        $keysDir = $this->hostDir.'/meshline-keys';
        $pub = $keysDir.'/server.key.pub';
        $priv = $keysDir.'/server.key';
        if (is_file($pub)) {
            return [
                'publicKey' => trim((string) file_get_contents($pub)),
                'privateKey' => is_file($priv) ? trim((string) file_get_contents($priv)) : null,
            ];
        }
        throw new \RuntimeException('WG_SERVER_PUBLIC_KEY is not configured and no host server public key is mounted. Refusing to generate a new server keypair inside the app container.');
    }

    public function getNextAvailableIp(): string
    {
        $used = [];
        try {
            foreach (DB::table('mikrotiks')->pluck('wireguard_ip') as $ip) {
                if ($ip) {
                    $used[(string) $ip] = true;
                }
            }
        } catch (\Throwable $e) {
            // table/column may not exist yet
        }
        foreach ($this->peerFiles() as $name => $_) {
            if (str_ends_with($name, '.peer')) {
                $used[substr($name, 0, -5)] = true;
            }
        }
        $status = $this->readStatus();
        foreach ($status['allowedIps'] ?? [] as $ip) {
            $used[(string) $ip] = true;
        }
        // Scan the whole /16, as the Node implementation does.
        for ($b = 0; $b <= 255; $b++) {
            for ($i = $b === 0 ? 2 : 1; $i <= 254; $i++) {
                $ip = "10.7.$b.$i";
                if (! isset($used[$ip])) {
                    return $ip;
                }
            }
        }
        throw new \RuntimeException('No available IPs in WireGuard network');
    }

    private function ensurePeersDir(): bool
    {
        if (is_dir($this->peersDir)) {
            return true;
        }

        return @mkdir($this->peersDir, 0700, true);
    }

    /** @return array<string,string> file name => contents */
    private function peerFiles(): array
    {
        $out = [];
        if (! is_dir($this->peersDir)) {
            return $out;
        }
        foreach (scandir($this->peersDir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $this->peersDir.'/'.$name;
            if (is_file($path)) {
                $out[$name] = (string) file_get_contents($path);
            }
        }

        return $out;
    }

    private function peerFilePath(string $ip): string
    {
        return $this->peersDir.'/'.preg_replace('/[^0-9.]/', '', $ip).'.peer';
    }

    public function writePeerFile(string $routerId, string $publicKey, string $ip, string $presharedKey = ''): bool
    {
        if (! $this->ensurePeersDir() || ! $publicKey || ! $ip) {
            return false;
        }
        $target = $this->peerFilePath($ip);
        foreach ($this->peerFiles() as $name => $body) {
            if ($name === basename($target)) {
                continue;
            }
            $parts = explode("\t", $body);
            if (($parts[0] ?? '') === (string) $routerId) {
                @unlink($this->peersDir.'/'.$name);
            }
        }
        $written = @file_put_contents($target, implode("\t", [
            (string) $routerId, $ip, $publicKey, $presharedKey,
        ])."\n", LOCK_EX);
        @chmod($target, 0600);

        return $written !== false;
    }

    /** Publish every approved router from the database. */
    public function syncPeerFilesFromDb(): bool
    {
        if (! $this->ensurePeersDir()) {
            return false;
        }
        $peers = [];
        try {
            $rows = DB::table('mikrotiks')
                ->where('wireguard_configured', 1)
                ->whereNotNull('wireguard_ip')->where('wireguard_ip', '!=', '')
                ->whereNotNull('wireguard_public_key')->where('wireguard_public_key', '!=', '')
                ->whereIn('setup_status', ['configuring', 'configured'])
                ->get(['router_id', 'wireguard_ip', 'wireguard_public_key']);
            foreach ($rows as $row) {
                $peers[(string) $row->router_id] = $row;
            }
        } catch (\Throwable $e) {
            return false;
        }
        foreach ($peers as $id => $row) {
            $this->writePeerFile($id, (string) $row->wireguard_public_key, (string) $row->wireguard_ip, '');
        }
        foreach ($this->peerFiles() as $name => $body) {
            $parts = explode("\t", $body);
            if (str_ends_with($name, '.peer') && ! isset($peers[$parts[0] ?? ''])) {
                @unlink($this->peersDir.'/'.$name);
            }
        }

        return true;
    }

    public function triggerApply(): bool
    {
        if (! $this->ensurePeersDir()) {
            return false;
        }

        return @file_put_contents($this->peersDir.'/.apply', (string) time(), LOCK_EX) !== false;
    }

    public function applyServerConfig(): array
    {
        $this->syncPeerFilesFromDb();
        $this->triggerApply();
        $status = $this->getServerStatus();

        return [
            'success' => true,
            'message' => ! empty($status['running'])
                ? 'WireGuard server configured and running'
                : 'Peer descriptors published — watcher will apply shortly',
            'status' => $status,
        ];
    }

    public function addPeerToServer(string $routerId, string $routerPublicKey, string $routerIp): array
    {
        if (! $this->writePeerFile($routerId, $routerPublicKey, $routerIp, '')) {
            return ['success' => false, 'message' => 'Could not write WireGuard peer descriptor'];
        }
        $this->triggerApply();

        return ['success' => true];
    }

    public function removePeerFromServer(string $routerPublicKey): array
    {
        $removed = false;
        foreach ($this->peerFiles() as $name => $body) {
            if (! str_ends_with($name, '.peer')) {
                continue;
            }
            $parts = explode("\t", $body);
            if (($parts[2] ?? '') === $routerPublicKey) {
                @unlink($this->peersDir.'/'.$name);
                $removed = true;
            }
        }
        $this->triggerApply();

        return ['success' => true, 'removed' => $removed];
    }

    private function readStatus(): array
    {
        if (! is_file($this->statusFile)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($this->statusFile), true);

        return is_array($decoded) ? $decoded : [];
    }

    public function getServerStatus(): array
    {
        $base = [
            'running' => false,
            'interface' => $this->interfaceName,
            'listenPort' => $this->listenPort,
            'serverIp' => $this->serverIp,
            'serverPublicIp' => $this->serverPublicIp,
            'network' => $this->networkCidr,
        ];
        $status = $this->readStatus();
        if ($status) {
            return array_merge($base, $status);
        }
        $configExists = is_file($this->hostDir.'/'.$this->interfaceName.'.conf');
        $peers = [];
        try {
            foreach (DB::table('mikrotiks')
                ->where('wireguard_configured', 1)->whereNotNull('wireguard_ip')
                ->get(['router_id', 'router_name', 'wireguard_ip', 'wireguard_public_key', 'status', 'last_seen']) as $row) {
                $peers[] = [
                    'routerId' => $row->router_id,
                    'name' => $row->router_name,
                    'wgIp' => $row->wireguard_ip,
                    'publicKey' => $row->wireguard_public_key,
                    'status' => $row->status,
                    'lastSeen' => $row->last_seen,
                ];
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return array_merge($base, ['running' => $configExists, 'peers' => $peers]);
    }

    public function getWireGuardPeers(): array
    {
        $status = $this->readStatus();
        $peers = [];
        try {
            $rows = DB::table('mikrotiks')
                ->where('wireguard_configured', 1)->orWhereNotNull('wireguard_ip')
                ->orderBy('wireguard_ip')
                ->get(['router_id', 'router_name', 'wireguard_ip', 'wireguard_public_key',
                    'status', 'last_seen', 'wireguard_configured', 'setup_status']);
        } catch (\Throwable $e) {
            return ['success' => true, 'peers' => []];
        }
        $configured = $rows->filter(fn ($p) => (int) $p->wireguard_configured === 1 && $p->setup_status === 'configured');
        $single = ! empty($status['running']) && (int) ($status['activePeers'] ?? 0) === 1 && $configured->count() === 1
            ? $configured->first()->router_id
            : null;
        $now = time();
        foreach ($rows as $row) {
            $handshake = (int) ($status['peerHandshakes'][$row->wireguard_public_key] ?? 0);
            $fresh = $handshake > 0 && ($now - $handshake) <= 180;
            $hasData = isset($status['peerHandshakes']);
            $active = $single !== null && (string) $row->router_id === (string) $single;
            $online = $hasData ? ! empty($status['running']) && $fresh : $active;
            $observedAt = (int) ($status['updatedAt'] ?? 0);
            $peers[] = [
                'routerId' => $row->router_id,
                'name' => $row->router_name,
                'wgIp' => $row->wireguard_ip,
                'publicKey' => $row->wireguard_public_key,
                'status' => $online ? 'online' : ($hasData ? 'offline' : $row->status),
                'lastSeen' => $handshake > 0 ? gmdate('c', $handshake)
                    : ($active && $observedAt ? gmdate('c', $observedAt) : $row->last_seen),
            ];
        }

        return ['success' => true, 'peers' => $peers];
    }

    public function saveRouterWireGuard(string $routerId, string $ip, string $publicKey, string $privateKey, string $presharedKey): void
    {
        try {
            DB::table('mikrotiks')->where('router_id', $routerId)->update([
                'wireguard_ip' => $ip,
                'wireguard_public_key' => $publicKey,
                'wireguard_private_key' => $privateKey,
                'wireguard_preshared_key' => $presharedKey,
                'wireguard_configured' => 1,
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // columns may not exist in older schemas
        }
    }

    public function generateServerConfig(array $peers = []): string
    {
        $config = "# Meshline WiFi WireGuard Server\n[Interface]\nAddress = {$this->serverIp}/24\nListenPort = {$this->listenPort}\nPrivateKey = <SERVER_PRIVATE_KEY>\n";
        foreach ($peers as $peer) {
            $config .= "\n[Peer]\nPublicKey = ".($peer['publicKey'] ?? '')."\nAllowedIPs = ".($peer['allowedIp'] ?? '')."/32\n";
        }

        return $config;
    }

    public function generateMikroTikConfig(array $options): string
    {
        $private = $options['privateKey'] ?? '';
        $serverPublic = $options['serverPublicKey'] ?? '';
        $endpoint = $options['serverEndpoint'] ?? $this->serverPublicIp;
        $ip = $options['assignedIp'] ?? '';

        return <<<RSC
# WireGuard Configuration — {$options['routerName']}
/interface wireguard add name=wg-meshline listen-port=13231 private-key="{$private}"
/interface wireguard peers add interface=wg-meshline public-key="{$serverPublic}" endpoint-address={$endpoint} endpoint-port={$this->listenPort} allowed-address={$this->serverIp}/32 persistent-keepalive=25s
/ip address add address={$ip}/32 interface=wg-meshline
/ip route add dst-address={$this->serverIp}/32 gateway=wg-meshline
/ip firewall filter add chain=input protocol=udp dst-port=13231 action=accept comment="WireGuard Input"
/ip firewall filter add chain=forward in-interface=wg-meshline action=accept comment="WireGuard Fwd In"
/ip firewall filter add chain=forward out-interface=wg-meshline action=accept comment="WireGuard Fwd Out"
/ip service set api address={$this->networkCidr}
RSC;
    }

    public function getVpsSetupGuide(): string
    {
        return "# This server IS the WireGuard hub. No external VPS needed.\n"
            ."# WireGuard is managed automatically by the system.\n"
            ."# Server public host: {$this->serverPublicIp}\n"
            ."# WireGuard port: {$this->listenPort}\n"
            ."# Network: {$this->networkCidr}";
    }
}
