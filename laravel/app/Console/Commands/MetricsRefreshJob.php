<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Ports the gauge refresh half of utils/monitoring.js. There is no PHP
 * prom-client in the port: gauges are written to the cache and to a static
 * text file that nginx can expose at /metrics. Opt-in only.
 */
#[AsCommand(name: 'meshline:jobs:metrics', description: 'Refresh Meshline WiFi gauges into cache and storage/app/meshline-metrics.prom')]
class MetricsRefreshJob extends Command
{
    protected $signature = 'meshline:jobs:metrics {--force : Run even when METRICS_REFRESH_ENABLED is false} {--show : Print the generated metrics}';

    public const CACHE_PREFIX = 'hotbando:metrics:';

    public function handle(): int
    {
        if (! $this->option('force') && ! filter_var(env('METRICS_REFRESH_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->warn('Metrics refresh is disabled (set METRICS_REFRESH_ENABLED=true or use --force).');

            return self::SUCCESS;
        }

        $providers = [
            'active_users' => fn () => DB::table('users')
                ->where('role', 'customer')->where('usage_until', '>', now())->count(),
            'online_routers' => fn () => DB::table('mikrotiks')->where('status', 'online')->count(),
            'total_routers' => fn () => DB::table('mikrotiks')->count(),
            'active_campaigns' => fn () => DB::table('campaigns')->where('is_active', 1)->count(),
            'wallet_balance_total' => fn () => (float) (DB::table('wallets')->sum('balance') ?? 0),
            'daily_revenue' => fn () => (float) (DB::table('transactions')
                ->whereDate('created_at', now()->toDateString())
                ->where('status', 'completed')
                ->sum('amount') ?? 0),
            'campaign_completions_daily' => fn () => DB::table('campaign_completions')
                ->whereDate('completed_at', now()->toDateString())->count(),
        ];

        $metrics = [];
        foreach ($providers as $name => $provider) {
            try {
                $metrics[$name] = (float) $provider();
            } catch (\Throwable $e) {
                // One missing legacy table must not stop the other gauges.
                try {
                    $previous = Cache::get(self::CACHE_PREFIX.$name, 0);
                } catch (\Throwable $cacheError) {
                    $previous = 0;
                }
                $metrics[$name] = (float) $previous;
                $this->warn("Metric {$name}: {$e->getMessage()}");
            }
        }

        try {
            foreach ($metrics as $name => $value) {
                Cache::put(self::CACHE_PREFIX.$name, $value, now()->addMinutes(2));
            }
        } catch (\Throwable $e) {
            Log::warning('Could not cache metrics: '.$e->getMessage());
        }

        $text = $this->render($metrics);
        try {
            $file = storage_path('app/meshline-metrics.prom');
            if (! is_dir(dirname($file))) {
                @mkdir(dirname($file), 0775, true);
            }
            $tmp = $file.'.tmp';
            if (@file_put_contents($tmp, $text) !== false) {
                @rename($tmp, $file);
            }
        } catch (\Throwable $e) {
            Log::warning('Could not write metrics file: '.$e->getMessage());
        }

        if ($this->option('show')) {
            $this->line($text);
        }

        return self::SUCCESS;
    }

    private function render(array $metrics): string
    {
        $help = [
            'active_users' => 'Number of active users (unexpired sessions)',
            'online_routers' => 'Number of online routers',
            'total_routers' => 'Total registered routers',
            'active_campaigns' => 'Number of active campaigns',
            'wallet_balance_total' => 'Total balance across all wallets',
            'daily_revenue' => 'Daily revenue total',
            'campaign_completions_daily' => 'Campaign completions today',
        ];

        $lines = [];
        foreach ($metrics as $name => $value) {
            $key = 'meshline_'.$name;
            $lines[] = "# HELP {$key} ".($help[$name] ?? $name);
            $lines[] = "# TYPE {$key} gauge";
            $lines[] = $key.' '.(float) $value;
        }

        return implode("\n", $lines)."\n";
    }
}
