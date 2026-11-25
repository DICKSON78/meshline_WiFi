<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use App\Support\LegacySession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/** Shared (Redis-backed) replacement for the legacy in-memory Map limiter. */
class ThrottleApi
{
    public function handle(Request $request, Closure $next, int $max = 1200, int $window = 900): Response
    {
        if (! $request->is('api/*')) {
            return $next($request);
        }

        $account = LegacySession::user($request);
        $identifier = ! empty($account['id'])
            ? 'account:'.($account['role'] ?? 'customer').':'.$account['id']
            : 'ip:'.$request->ip();
        $key = 'hotbando:throttle:'.hash('sha256', $identifier);
        try {
            $store = Cache::store();
            $count = $store->add($key, 1, $window) ? 1 : (int) $store->increment($key, 1);
        } catch (\Throwable $e) {
            // Rate limiting must never take the portal down when Redis/cache is unavailable.
            report($e);

            return $next($request);
        }
        $remaining = max(0, $max - $count);
        $response = $count > $max
            ? ApiResponse::json([
                'success' => false,
                'error' => 'Too many requests, please try again later',
                'retryAfter' => $window,
            ], 429)
            : $next($request);
        $response->headers->set('X-RateLimit-Limit', (string) $max);
        $response->headers->set('X-RateLimit-Remaining', (string) $remaining);
        $response->headers->set('X-RateLimit-Reset', (string) (time() + $window));

        return $response;
    }
}
