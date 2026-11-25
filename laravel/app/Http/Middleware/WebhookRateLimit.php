<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class WebhookRateLimit
{
    public function handle(Request $request, Closure $next): Response
    {
        $window = (int) (env('CLICKPESA_WEBHOOK_RATE_LIMIT_WINDOW_MS', 60000) / 1000);
        $max = (int) env('CLICKPESA_WEBHOOK_RATE_LIMIT_MAX', 60);
        $key = 'hotbando:webhook:'.hash('sha256', $request->ip());
        try {
            $store = Cache::store();
            $count = $store->add($key, 1, $window) ? 1 : (int) $store->increment($key, 1);
        } catch (\Throwable $e) {
            report($e);

            return $next($request);
        }
        if ($count > $max) {
            return response()->json(['status' => 'rate_limited'], 429);
        }

        return $next($request);
    }
}
