<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use App\Support\LegacySession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Port of middleware/authMiddleware.js.  Aliases keep the legacy
 * backward-compatible role sets (e.g. sponsorAuth also accepts admins).
 */
class RoleAuth
{
    private const ALIASES = [
        'admin' => ['admin', 'super_admin'],
        'super_admin' => ['super_admin'],
        'sponsor' => ['sponsor', 'bank_partner', 'admin', 'super_admin'],
        'bank' => ['bank_partner', 'admin', 'super_admin'],
        'franchise' => ['franchise_owner', 'admin', 'super_admin'],
        'reseller' => ['reseller', 'admin', 'super_admin'],
        'investor' => ['investor', 'franchise_owner', 'admin', 'super_admin'],
        'customer' => ['customer', 'admin', 'super_admin'],
    ];

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $allowed = [];
        foreach ($roles as $role) {
            foreach (self::ALIASES[$role] ?? [$role] as $expanded) {
                $allowed[] = $expanded;
            }
        }
        $allowed = array_values(array_unique($allowed));

        if (! LegacySession::isAuthenticated($request)) {
            return $request->expectsJson() || $request->is('api/*')
                ? ApiResponse::unauthorized()
                : redirect('/login');
        }
        if ($allowed && ! LegacySession::hasRole($request, $allowed)) {
            $role = LegacySession::role($request);
            if ($request->expectsJson() || $request->is('api/*')) {
                return ApiResponse::forbidden();
            }

            return redirect(LegacySession::dashboardFor($role));
        }

        return $next($request);
    }
}
