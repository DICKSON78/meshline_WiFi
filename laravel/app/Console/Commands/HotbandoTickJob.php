<?php

namespace App\Console\Commands;

use App\Services\Routers\RouterManager;
use App\Support\Hotbando;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * One-minute housekeeping tick. Ports utils/expiryScheduler.js and the two
 * payment calls made by the Node scheduler.
 *
 * Each job is isolated: a router or provider failure must never prevent the
 * remaining jobs in the same tick from running.
 */
#[AsCommand(name: 'hotbando:jobs:tick', description: 'Run the HotBando expiry / free-data / payment tick')]
class HotbandoTickJob extends Command
{
    protected $signature = 'hotbando:jobs:tick {--only= : Comma-separated subset: expiry,free-bytes,payments,payouts}';

    public const FREE_TIER = 'KARIBU HOT BANDO';

    private const PAYMENT_FULFILLMENT = 'App\\Services\\Payment\\PaymentFulfillment';

    public function handle(RouterManager $routers): int
    {
        $only = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $this->option('only'))
        )));

        $jobs = [
            'expiry' => fn (): int => $this->disconnectExpiredUsers($routers),
            'free-bytes' => fn (): int => $this->consumeFreeBytes($routers),
            'payments' => fn (): int => $this->reconcilePayments(),
            'payouts' => fn (): int => $this->processPayouts(),
        ];

        foreach ($jobs as $name => $job) {
            if ($only !== [] && ! in_array($name, $only, true)) {
                continue;
            }

            try {
                $affected = $job();
                $this->line(sprintf('<info>[tick]</info> %s: %d', $name, $affected));
            } catch (\Throwable $e) {
                // Mirrors the independent try/catch blocks in the legacy tick().
                logger()->error("HotBando tick job '{$name}' failed: {$e->getMessage()}", [
                    'exception' => $e,
                ]);
                $this->warn("[tick] {$name}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Expired paid packages are reset to the free tier and their active router
     * sessions are kicked. The database grant is always cleared, even when the
     * device is unreachable or has no usable MAC/router id.
     */
    public function disconnectExpiredUsers(RouterManager $routers): int
    {
        $now = Carbon::now();

        $candidates = DB::table('users')
            ->select('id', 'name', 'phone_number', 'mac_address', 'last_router_id', 'usage_until')
            ->where('role', 'customer')
            ->where('is_active', 1)
            ->where('package', '!=', self::FREE_TIER)
            ->whereNotNull('usage_until')
            // SQL only narrows the candidates; the app clock decides expiry.
            ->where('usage_until', '<', $now->copy()->addDay())
            ->get();

        $count = 0;
        foreach ($candidates as $user) {
            if (! $this->isExpired($user->usage_until, $now)) {
                continue;
            }

            $kicked = $this->kickRouterSessions($routers, $user->mac_address, $user->last_router_id);

            try {
                DB::table('users')->where('id', $user->id)->update([
                    'package' => self::FREE_TIER,
                    'usage_until' => null,
                    'usage_start' => null,
                    'updated_at' => $now,
                ]);
            } catch (\Throwable $e) {
                $this->warn("[expiry] Could not reset user {$user->id}: {$e->getMessage()}");
                continue;
            }

            $this->logRouterEvent($user, $kicked);
            $count++;
        }

        return $count;
    }

    /**
     * Charge closed RADIUS accounting rows against free_bytes exactly once.
     */
    public function consumeFreeBytes(RouterManager $routers): int
    {
        $rows = DB::table('user_connection_logs')
            ->select('user_id')
            ->selectRaw('SUM(COALESCE(bytes_uploaded, 0) + COALESCE(bytes_downloaded, 0)) AS bytes_used')
            ->where('action', 'disconnect')
            ->where('bytes_charged', 0)
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->get();

        $count = 0;
        foreach ($rows as $row) {
            $userId = (int) $row->user_id;
            $used = max(0, (int) $row->bytes_used);

            try {
                $result = DB::transaction(function () use ($userId, $used) {
                    $user = DB::table('users')->where('id', $userId)->lockForUpdate()
                        ->first(['id', 'name', 'mac_address', 'last_router_id', 'free_bytes', 'usage_until']);

                    $remaining = $user ? max(0, (int) $user->free_bytes - $used) : 0;
                    if ($used > 0 && $user) {
                        DB::table('users')->where('id', $userId)->update([
                            'free_bytes' => $remaining,
                            'updated_at' => now(),
                        ]);
                    }

                    DB::table('user_connection_logs')
                        ->where('user_id', $userId)
                        ->where('action', 'disconnect')
                        ->where('bytes_charged', 0)
                        ->update(['bytes_charged' => 1]);

                    return ['user' => $user, 'remaining' => $remaining];
                });
            } catch (\Throwable $e) {
                $this->warn("[free-bytes] User {$userId}: {$e->getMessage()}");
                continue;
            }

            $user = $result['user'];
            if ($user && $result['remaining'] <= 0 && ! $this->hasRemainingTime($user->usage_until)) {
                $this->kickRouterSessions($routers, $user->mac_address, $user->last_router_id);
            }
            $count++;
        }

        return $count;
    }

    /**
     * PaymentFulfillment is owned by the payments agent; the tick degrades
     * gracefully if the class or one of its methods is unavailable.
     */
    public function reconcilePayments(): int
    {
        $class = self::PAYMENT_FULFILLMENT;
        if (! class_exists($class) || ! is_callable([$class, 'reconcile'])) {
            $this->warn('[payments] PaymentFulfillment::reconcile() unavailable; skipped');

            return 0;
        }

        return (int) $class::reconcile();
    }

    public function processPayouts(): int
    {
        $class = self::PAYMENT_FULFILLMENT;
        if (! class_exists($class) || ! is_callable([$class, 'processPayouts'])) {
            $this->warn('[payouts] PaymentFulfillment::processPayouts() unavailable; skipped');

            return 0;
        }

        return (int) $class::processPayouts();
    }

    private function isExpired(mixed $value, Carbon $now): bool
    {
        if (! $value) {
            return false;
        }

        try {
            return Carbon::parse($value)->lt($now);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function hasRemainingTime(mixed $value): bool
    {
        if (! $value) {
            return false;
        }

        try {
            return Carbon::parse($value)->gt(now());
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Best-effort disconnect through the driver contract. Never throws.
     */
    private function kickRouterSessions(RouterManager $routers, ?string $mac, ?string $routerId): int
    {
        if (! $mac || ! $routerId) {
            return 0;
        }

        try {
            $router = $routers->row($routerId);
            if (! $router || (int) ($router['is_active'] ?? 0) !== 1) {
                $this->warn("[router] {$routerId} is not a registered/active router; skipped disconnect");

                return 0;
            }

            $session = $routers->connect($router);
            $active = $session->driver->activeSessions($session, $mac);
            $kicked = 0;

            foreach ($active as $entry) {
                $sessionId = $this->sessionIdentifier($entry);
                if ($sessionId !== null && $session->driver->kickSession($session, $sessionId)) {
                    $kicked++;
                }
            }

            if ($kicked > 0) {
                Hotbando::activity(
                    null,
                    'disconnect',
                    'router_session',
                    null,
                    "Disconnected {$mac} from {$routerId} ({$kicked} session(s))"
                );
            }

            return $kicked;
        } catch (\Throwable $e) {
            // CapabilityException on Omada, timeouts, auth failures, etc.
            $this->warn("[router] Could not disconnect {$mac} from {$routerId}: {$e->getMessage()}");

            return 0;
        }
    }

    private function sessionIdentifier(mixed $entry): ?string
    {
        if (is_array($entry)) {
            foreach (['.id', 'id', 'session_id'] as $key) {
                if (isset($entry[$key]) && is_scalar($entry[$key])) {
                    return (string) $entry[$key];
                }
            }

            return null;
        }

        if (is_object($entry)) {
            foreach (get_object_vars($entry) as $key => $value) {
                if (in_array($key, ['.id', 'id', 'session_id'], true) && is_scalar($value)) {
                    return (string) $value;
                }
            }
        }

        return null;
    }

    private function logRouterEvent(object $user, int $kicked): void
    {
        try {
            $router = DB::table('mikrotiks')->where('router_id', $user->last_router_id)->first(['id']);
            if (! $router) {
                return;
            }

            DB::table('user_connection_logs')->insert([
                'mac_address' => $user->mac_address,
                'router_id' => $router->id,
                'user_id' => $user->id,
                'action' => 'disconnect',
                'error_message' => $kicked > 0 ? 'Package expired' : 'Package expired (router unreachable)',
            ]);
        } catch (\Throwable $e) {
            // Logging is best-effort; the grant reset already happened.
        }
    }

}
