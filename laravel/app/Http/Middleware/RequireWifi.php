<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use App\Support\LegacySession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * requireWifi from routes/hotspotApi.js.  An offline but registered router is
 * reported as 503 so the Vue dashboard can show a specific message instead of
 * the old generic "Imeshindikana kupakia taarifa".
 */
class RequireWifi
{
    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->session();
        $user = LegacySession::user($request);
        $onWifi = (bool) $session->get('onWifi');
        $routerId = $session->get('routerID');

        if (! $onWifi && $user && ! empty($user['last_router_id'])) {
            try {
                $router = DB::table('mikrotiks')
                    ->where('id', $user['last_router_id'])
                    ->orWhere('router_id', $user['last_router_id'])
                    ->first(['id', 'status', 'name', 'router_id']);
                if ($router && $this->routerOnline($router)) {
                    $session->put('onWifi', true);
                    $session->put('routerID', $router->router_id ?? $router->id);
                    $onWifi = true;
                } elseif ($router) {
                    if ($request->is('api/*')) {
                        return ApiResponse::json([
                            'error' => 'Ruta iko nje ya mtandao (offline)',
                            'onWifi' => false,
                            'routerStatus' => 'offline',
                        ], 503);
                    }

                    return redirect('/hotspot');
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        if (! $onWifi) {
            if ($request->is('api/*')) {
                return ApiResponse::json([
                    'error' => 'Uko nje ya WiFi ya Meshline WiFi',
                    'onWifi' => false,
                    'redirectTo' => '/hotspot',
                ], 403);
            }

            return redirect('/hotspot');
        }

        return $next($request);
    }

    private function routerOnline(object $router): bool
    {
        $status = strtolower((string) ($router->status ?? ''));
        if (in_array($status, ['online', 'active', 'connected'], true)) {
            return true;
        }
        if (empty($router->host) && empty($router->ip)) {
            return $status !== 'offline';
        }

        return false;
    }
}
