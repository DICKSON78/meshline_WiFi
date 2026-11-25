<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Ports utils/routerStatusMonitor.js: warn (never mutate) when a configured
 * router has not been seen for 10 minutes.
 */
#[AsCommand(name: 'hotbando:jobs:stale-routers', description: 'Log a warning for configured routers unseen for 10 minutes')]
class StaleRouterJob extends Command
{
    protected $signature = 'hotbando:jobs:stale-routers {--threshold=10 : Minutes without a heartbeat before a router is stale}';

    public const STALE_THRESHOLD_MINUTES = 10;

    public function handle(): int
    {
        $threshold = max(1, (int) $this->option('threshold'));
        $cutoff = now()->subMinutes($threshold);

        try {
            $rows = DB::table('mikrotiks')
                ->select('router_id', 'router_name', 'status', 'setup_status', 'last_seen')
                ->where('setup_status', 'configured')
                ->where(function ($query) use ($cutoff) {
                    $query->whereNull('last_seen')->orWhere('last_seen', '<', $cutoff);
                })
                ->get();

            foreach ($rows as $row) {
                $minutes = null;
                if ($row->last_seen) {
                    try {
                        $minutes = Carbon::parse($row->last_seen)->diffInMinutes(now());
                    } catch (\Throwable $e) {
                        $minutes = null;
                    }
                }

                $message = sprintf(
                    'Router %s (%s) is stale - last seen %s minutes ago. Status: %s',
                    $row->router_id,
                    $row->router_name,
                    $minutes ?? '?',
                    $row->status ?? 'unknown'
                );

                Log::warning($message);
                $this->warn($message);
            }
        } catch (\Throwable $e) {
            Log::error('Stale router check failed: '.$e->getMessage(), ['exception' => $e]);
            $this->warn('Stale router check failed: '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
