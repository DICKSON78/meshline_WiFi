<?php

namespace App\Services\Routers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Port of the remote-repair state machine in routes/zeroTouch.js.
 *
 *   <id>.rsc       pending, applied on the next heartbeat
 *   <id>.applying  locked while a process is applying it
 *   <id>.applied   done (kept for audit)
 *   <id>.failed    gave up after 3 attempts
 *
 * Renames are the cross-process lock.  One-time download tokens are kept on
 * disk (with an optional DB mirror) so any PHP worker can serve the script.
 */
class RepairService
{
    private const MAX_ATTEMPTS = 3;

    private const APPLY_TIMEOUT_MS = 180000;

    private string $dir;

    public function __construct(private readonly RouterManager $manager = new RouterManager())
    {
        $root = base_path('../data/router-repairs');
        $this->dir = is_dir($root) ? realpath($root) : base_path('data/router-repairs');
        if (! is_dir($this->dir)) {
            @mkdir($this->dir, 0700, true);
        }
    }

    private function safeId(mixed $routerId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '', (string) ($routerId ?? ''));

        return ($safe && ! str_contains($safe, '..')) ? $safe : '';
    }

    private function path(string $safeId, string $suffix): string
    {
        return $this->dir.'/'.$safeId.'.'.$suffix;
    }

    private function read(string $safeId, string $suffix): ?string
    {
        $file = $this->path($safeId, $suffix);

        return is_file($file) ? (string) file_get_contents($file) : null;
    }

    private function attempts(string $safeId): int
    {
        return (int) trim((string) ($this->read($safeId, 'attempts') ?? '0'));
    }

    public function log(string $safeId, string $message): void
    {
        try {
            $lines = array_filter(explode("\n", (string) ($this->read($safeId, 'log') ?? '')));
            $lines[] = gmdate('c').' '.$message;
            file_put_contents($this->path($safeId, 'log'), implode("\n", array_slice($lines, -30))."\n", LOCK_EX);
        } catch (\Throwable $e) {
            // Diagnostics must never break a repair.
        }
    }

    private function restorePending(string $safeId, string $reason): void
    {
        $attempts = $this->attempts($safeId) + 1;
        @file_put_contents($this->path($safeId, 'error'), gmdate('c')." attempt {$attempts}: {$reason}");
        if ($attempts >= self::MAX_ATTEMPTS) {
            @rename($this->path($safeId, 'applying'), $this->path($safeId, 'failed'));
            Log::error('Router repair failed permanently', ['router' => $safeId, 'attempts' => $attempts, 'reason' => $reason]);
        } else {
            @file_put_contents($this->path($safeId, 'attempts'), (string) $attempts);
            @rename($this->path($safeId, 'applying'), $this->path($safeId, 'rsc'));
            Log::warning('Router repair failed, retrying on next heartbeat', ['router' => $safeId, 'attempts' => $attempts]);
        }
    }

    public function issueToken(string $safeId): string
    {
        $token = bin2hex(random_bytes(24));
        $expires = time() + 15 * 60;
        @file_put_contents($this->path($safeId, 'token'), json_encode([
            'token' => $token, 'expires' => $expires * 1000,
        ]), LOCK_EX);

        try {
            DB::table('router_repair_tokens')->updateOrInsert(
                ['token' => $token],
                ['router' => $safeId, 'expires_at' => gmdate('Y-m-d H:i:s', $expires)]
            );
        } catch (\Throwable $e) {
            // table may not be installed; the token file is authoritative
        }

        return $token;
    }

    public function findToken(string $token): ?string
    {
        if ($token === '') {
            return null;
        }
        foreach (glob($this->dir.'/*.token') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data) && ($data['token'] ?? '') === $token) {
                return ((int) ($data['expires'] ?? 0) >= time() * 1000)
                    ? basename($file, '.token')
                    : null;
            }
        }
        try {
            $row = DB::table('router_repair_tokens')->where('token', $token)
                ->where('expires_at', '>', now())->first();
            if ($row) {
                return (string) $row->router;
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return null;
    }

    public function consumeToken(string $token, string $safeId): void
    {
        @unlink($this->path($safeId, 'token'));
        try {
            DB::table('router_repair_tokens')->where('token', $token)->delete();
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /** One-time script download; returns null when the token is absent/used. */
    public function scriptForToken(string $token): ?string
    {
        $safeId = $this->findToken($token);
        if (! $safeId) {
            return null;
        }
        $source = $this->read($safeId, 'applying') ?? $this->read($safeId, 'rsc');
        if ($source === null) {
            return null;
        }
        $this->consumeToken($token, $safeId);
        $this->log($safeId, 'script downloaded by router');

        return $source;
    }

    /**
     * Called on every zero-touch heartbeat.  The lock is acquired before any
     * network work; the actual fetch+import is intentionally last so a stuck
     * router cannot block the heartbeat response.
     */
    public function maybeApplyRepair(mixed $routerId): ?array
    {
        $safeId = $this->safeId($routerId);
        if ($safeId === '') {
            return null;
        }
        // Recover stale locks (a process may have died mid-apply).
        $lock = $this->path($safeId, 'applying');
        if (is_file($lock) && time() - filemtime($lock) > 300) {
            @rename($lock, $this->path($safeId, 'rsc'));
            $this->log($safeId, 'recovered stale lock');
        }
        if (! is_file($this->path($safeId, 'rsc'))) {
            return null;
        }
        if (! @rename($this->path($safeId, 'rsc'), $lock)) {
            return null;
        }
        touch($lock); // rename preserves the old mtime — reset it now

        $token = $this->issueToken($safeId);
        $base = rtrim((string) (env('PUBLIC_BASE_URL') ?: env('APP_URL') ?: 'https://hotbandowifi.online'), '/');
        $url = $base.'/api/zero-touch/repair-script/'.$token;
        $this->log($safeId, 'apply start (attempt '.($this->attempts($safeId) + 1).')');

        // The heartbeat controller defers this call to a shutdown callback so
        // the reply is already on the wire before we do slow I/O here.
        $outcome = 'failed';
        try {
            $row = DB::table('mikrotiks')->where('router_id', $routerId)->first();
            if (! $row) {
                throw new \RuntimeException('Router not registered');
            }
            $session = $this->manager->connect((array) $row);
            $outcome = $this->applyWithTimeout($session, $url, $safeId);
            $session->driver->disconnect($session);
        } catch (\Throwable $e) {
            $this->log($safeId, 'exception: '.$e->getMessage());
            $outcome = $e->getMessage();
        }
        $this->log($safeId, 'outcome='.($outcome === true ? 'applied' : $outcome));

        if ($outcome === true) {
            foreach (['attempts', 'error'] as $suffix) {
                @unlink($this->path($safeId, $suffix));
            }
            @rename($lock, $this->path($safeId, 'applied'));
            Log::info('Router repair applied successfully', ['router' => $safeId]);
        } else {
            $this->restorePending($safeId, $outcome === 'timeout' ? 'repair timed out' : (is_string($outcome) ? $outcome : 'apply returned false'));
        }

        return ['router' => $safeId, 'state' => $outcome === true ? 'applied' : 'retry'];
    }

    private function applyWithTimeout(RouterSession $session, string $url, string $safeId): bool|string
    {
        $started = microtime(true);
        $driver = $session->driver;
        if (! $driver instanceof MikrotikDriver) {
            throw new \RuntimeException('Remote repair requires a MikroTik router');
        }
        $result = $driver->fetchAndImportScript(
            $session, $url, 'hotbando-repair.rsc'
        );
        if ((microtime(true) - $started) * 1000 > self::APPLY_TIMEOUT_MS) {
            return 'timeout';
        }

        return $result;
    }

    /** State only — no script contents, no credentials. */
    public function repairState(mixed $routerId): array
    {
        $safeId = $this->safeId($routerId);
        if ($safeId === '') {
            return ['state' => 'unknown'];
        }
        $attempts = $this->attempts($safeId);
        $lastError = $this->read($safeId, 'error');
        $lastLog = $this->read($safeId, 'log');
        $applyingAge = is_file($this->path($safeId, 'applying'))
            ? time() - filemtime($this->path($safeId, 'applying')) : null;
        foreach (['applied', 'failed', 'applying', 'rsc'] as $state) {
            if (is_file($this->path($safeId, $state))) {
                $out = [
                    'router' => $safeId,
                    'state' => $state,
                    'attempts' => $attempts ?: null,
                ];
                if ($applyingAge !== null) {
                    $out['applyingAgeSec'] = $applyingAge;
                }
                if ($lastError) {
                    $out['lastError'] = trim($lastError);
                }
                if ($lastLog) {
                    $out['lastLog'] = trim($lastLog);
                }

                return array_filter($out, fn ($v, $k) => $v !== null || $k === 'state', ARRAY_FILTER_USE_BOTH);
            }
        }
        $out = ['router' => $safeId, 'state' => 'none'];
        if ($lastLog) {
            $out['lastLog'] = trim($lastLog);
        }

        return $out;
    }
}
