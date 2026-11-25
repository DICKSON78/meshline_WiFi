<?php

namespace App\Http\Controllers;

use App\Support\ApiResponse;
use App\Support\LegacySession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Portal (website/SPA) authentication API — port of the /api/csrf, /api/me,
 * /api/login and /api/logout handlers in the legacy index.js.
 */
class AuthController extends Controller
{
    /** Roles allowed to log in through the SPA (same allow-list as index.js). */
    private const LOGIN_ROLES = [
        'admin', 'super_admin', 'sponsor', 'bank_partner',
        'franchise_owner', 'reseller', 'investor',
    ];

    /** Exact dashboard redirects used by the legacy portal. */
    private const DASHBOARD_ROUTES = [
        'admin' => '/admin/dashboard',
        'super_admin' => '/admin/dashboard',
        'sponsor' => '/sponsor/dashboard',
        'bank_partner' => '/bank/dashboard',
        'franchise_owner' => '/franchise/dashboard',
        'reseller' => '/reseller/dashboard',
        'investor' => '/investor/dashboard',
    ];

    /** Dummy bcrypt hash used when no account matches (timing side-channel). */
    private const DUMMY_HASH = '$2a$12$HHmZsTRUdzYLIi3.d4hcG.1zFdGs0xpq8e6kSf/GEo80N5nUULjyC';

    public function csrf(): JsonResponse
    {
        return ApiResponse::json(
            ['csrfToken' => csrf_token()],
            200,
            ['Cache-Control' => 'no-store']
        );
    }

    public function me(Request $request): JsonResponse
    {
        $user = LegacySession::user($request);
        if (! $user) {
            return ApiResponse::json(['user' => null]);
        }

        $role = (string) ($user['role'] ?? '');

        return ApiResponse::json([
            'user' => LegacySession::publicUser($user),
            'redirect' => self::DASHBOARD_ROUTES[$role]
                ?? ($role === 'customer' ? '/hotspot/dashboard' : LegacySession::dashboardFor($role)),
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $email = $request->input('email');
        $password = $request->input('password');

        if (! $email || ! $password) {
            return ApiResponse::json(['error' => 'Email and password are required'], 400);
        }

        try {
            $row = DB::table('users')
                ->where('email', $email)
                ->whereIn('role', self::LOGIN_ROLES)
                ->where('is_active', 1)
                ->first();

            $valid = false;
            if ($row && ! empty($row->password)) {
                try {
                    $valid = Hash::check($password, $row->password);
                } catch (\Throwable $e) {
                    $valid = false;
                }
            } else {
                // Keep the timing profile of a real bcrypt verification.
                try {
                    Hash::check('dummy-notreal', self::DUMMY_HASH);
                } catch (\Throwable $e) {
                    // ignore
                }
            }

            if (! $row || ! $valid) {
                return ApiResponse::json(['error' => 'Invalid email or password'], 401);
            }

            $sessionData = [
                'id' => $row->id,
                'name' => $row->name,
                'email' => $row->email,
                'role' => $row->role,
                'phone_number' => $row->phone_number ?? null,
                'location' => $row->location ?? null,
                'login_time' => now()->toIso8601String(),
            ];

            // LegacySession::login flushes + regenerates the session and writes
            // exactly one role key — this mirrors req.session.regenerate() in
            // the Express handler.
            LegacySession::login($request, (string) $row->role, $sessionData);

            return ApiResponse::json([
                'user' => [
                    'id' => $row->id,
                    'name' => $row->name,
                    'email' => $row->email,
                    'role' => $row->role,
                    'phone_number' => $row->phone_number ?? null,
                    'location' => $row->location ?? null,
                ],
                'redirect' => self::DASHBOARD_ROUTES[$row->role] ?? '/login',
            ]);
        } catch (\Throwable $e) {
            report($e);

            return ApiResponse::fail('Server error. Please try again.', 500);
        }
    }

    public function logout(Request $request): JsonResponse
    {
        $request->session()->flush();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return ApiResponse::json(['success' => true]);
    }

    /** Public package listing used by the reseller portal (index.js). */
    public function packages(): JsonResponse
    {
        try {
            $packages = DB::table('packages')
                ->where('is_active', 1)
                ->orderBy('price', 'asc')
                ->get();

            return ApiResponse::json(['success' => true, 'packages' => $packages]);
        } catch (\Throwable $e) {
            report($e);

            return ApiResponse::fail('Failed to load packages', 500);
        }
    }
}
