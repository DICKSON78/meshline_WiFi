<?php

use App\Console\Commands\HotbandoTickJob;
use App\Console\Commands\MetricsRefreshJob;
use App\Console\Commands\StaleRouterJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Replacements for the timers started in index.js:
 *   expiryScheduler.start(60000)      -> every minute
 *   startStaleRouterMonitor()         -> every five minutes
 *   setInterval(refreshMetrics, 30000) -> every 30 seconds, opt-in
 *
 * Run `php artisan schedule:work` (or schedule:run from cron) once, next to
 * the web process. withoutOverlapping() mirrors the legacy `running` guard.
 */
Schedule::command(HotbandoTickJob::class)
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground();

Schedule::command(StaleRouterJob::class)
    ->everyFiveMinutes()
    ->withoutOverlapping(5)
    ->runInBackground();

if (filter_var(env('METRICS_REFRESH_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
    Schedule::command(MetricsRefreshJob::class)
        ->everyThirtySeconds()
        ->withoutOverlapping(25)
        ->runInBackground();
}
