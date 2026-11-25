<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The Express app kept one user object per role inside the session.  The Vue
 * client still reads GET /api/me and dashboard guards still inspect these
 * keys, so the Laravel port deliberately keeps the same shape.
 */
class LegacySession
{
    public const ROLE_KEYS = [
        'admin_user' => ['admin', 'super_admin'],
        'sponsor_user' => ['sponsor'],
        'bank_user' => ['bank_partner'],
        'franchise_user' => ['franchise_owner'],
        'reseller_user' => ['reseller'],
        'investor_user' => ['investor'],
        'hotbando_user' => ['customer'],
    ];

    public const DASHBOARDS = [
        'admin' => '/admin',
        'super_admin' => '/admin',
        'sponsor' => '/sponsor',
        'bank_partner' => '/bank',
        'franchise_owner' => '/franchise',
        'reseller' => '/reseller',
        'investor' => '/investor',
        'customer' => '/hotspot/dashboard',
    ];

    public static function all(Request $request): array
    {
        foreach (self::ROLE_KEYS as $key => $roles) {
            $user = $request->session()->get($key);
            if (is_array($user) && ! empty($user['id'])) {
                return $user;
            }
        }

        $user = $request->session()->get('user');

        return is_array($user) ? $user : [];
    }

    public static function user(Request $request): ?array
    {
        $user = self::all($request);

        return $user ?: null;
    }

    public static function role(Request $request): ?string
    {
        $user = self::user($request);

        return $user['role'] ?? null;
    }

    public static function id(Request $request): ?int
    {
        $user = self::user($request);

        return isset($user['id']) ? (int) $user['id'] : null;
    }

    public static function isAuthenticated(Request $request): bool
    {
        return self::user($request) !== null;
    }

    public static function hasRole(Request $request, array $roles): bool
    {
        $role = self::role($request);

        return $role !== null && in_array($role, $roles, true);
    }

    /**
     * Mirrors req.session.regenerate(): keep only the whitelisted verdicts
     * that are re-applied by the caller (MAC, router, etc.).
     */
    public static function login(Request $request, string $role, array $user, array $keep = []): array
    {
        $request->session()->flush();
        $request->session()->regenerate();
        unset($user['password'], $user['reset_token'], $user['verification_code']);
        $user['role'] = $role;
        $user['login_time'] = $user['login_time'] ?? now()->toIso8601String();

        $request->session()->put(self::keyForRole($role), $user);
        foreach ($keep as $name => $value) {
            $request->session()->put($name, $value);
        }
        $request->session()->save();

        return $user;
    }

    public static function keyForRole(string $role): string
    {
        foreach (self::ROLE_KEYS as $key => $roles) {
            if (in_array($role, $roles, true)) {
                return $key;
            }
        }

        return 'hotbando_user';
    }

    public static function dashboardFor(?string $role): string
    {
        return self::DASHBOARDS[$role] ?? '/login';
    }

    public static function publicUser(array $user): array
    {
        return array_intersect_key($user, array_flip([
            'id', 'name', 'email', 'role', 'phone_number', 'location', 'redirect',
        ]));
    }
}
