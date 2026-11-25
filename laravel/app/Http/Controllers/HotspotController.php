<?php

namespace App\Http\Controllers;

use App\Services\Routers\RouterManager;
use App\Support\ApiResponse;
use App\Support\Meshline;
use App\Support\LegacySession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Customer hotspot API — port of routes/hotspotApi.js and
 * controllers/hotspotController.js (the /api/hotspot prefix).
 */
class HotspotController extends Controller
{
    private const MIB = Meshline::MIB; // 1048576 — used for the default free-data allowance
    private const DUMMY_HASH = '$2a$12$HHmZsTRUdzYLIi3.d4hcG.1zFdGs0xpq8e6kSf/GEo80N5nUULjyC';

    // ------------------------------------------------------------------
    // Session / auth / device binding
    // ------------------------------------------------------------------

    public function session(Request $request): JsonResponse
    {
        try {
            $session = $request->session();
            $sessionUser = $this->sessionUser($request);

            $explicit = $request->query('routerID') ?: $request->query('routerid') ?: $request->query('router_id');
            $candidates = array_values(array_unique(array_filter([
                $explicit,
                $session->get('routerID'),
                $sessionUser['last_router_id'] ?? null,
            ])));

            $registeredRouter = null;
            foreach ($candidates as $candidate) {
                $row = $this->registeredRouterRow($candidate);
                if ($row) {
                    $registeredRouter = $row;
                    break;
                }
            }

            $mac = $this->clientMac($request) ?: $session->get('mac');
            $ssidshow = $this->ssidFromRequest($request);
            $location = $registeredRouter
                ? $registeredRouter->location
                : ($session->get('location') ?: 'Tanzania');

            $session->put([
                'mac' => $mac ?: $session->get('mac'),
                'routerID' => $registeredRouter ? $registeredRouter->router_id : null,
                'ssidshow' => $ssidshow ?: $session->get('ssidshow'),
                'location' => $location,
                'onWifi' => (bool) $registeredRouter,
                'clientIP' => $request->ip(),
                'userAgent' => $request->header('User-Agent'),
            ]);

            return ApiResponse::json([
                'mac' => $session->get('mac') ?: '',
                'routerID' => $session->get('routerID') ?: '',
                'ssidshow' => $session->get('ssidshow') ?: '',
                'location' => $session->get('location') ?: '',
                'onWifi' => (bool) $session->get('onWifi'),
                'registeredRouter' => $registeredRouter,
                'user' => $this->publicUser($sessionUser),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return ApiResponse::json(['error' => 'Imeshindikana kuweka kikao.'], 500);
        }
    }

    public function login(Request $request): JsonResponse
    {
        try {
            $rawPhone = $request->input('phone_number');
            $password = $request->input('password');
            $phone = $this->normalizePhone($rawPhone);

            if (! $phone || ! preg_match('/^0[0-9]{9}$/', $phone)) {
                return ApiResponse::json(['error' => 'Namba ya simu sio sahihi.'], 400);
            }
            if (! $password || strlen((string) $password) < 4) {
                return ApiResponse::json(['error' => 'Neno la siri linahitajika.'], 400);
            }

            $user = DB::table('users')
                ->where('phone_number', $phone)
                ->where('role', 'customer')
                ->where('is_active', 1)
                ->first();
            if (! $user && $rawPhone) {
                $user = DB::table('users')
                    ->where('phone_number', $rawPhone)
                    ->where('role', 'customer')
                    ->where('is_active', 1)
                    ->first();
            }

            if (! $user) {
                $this->dummyBcrypt();
                return ApiResponse::json(['error' => 'Neno la siri au namba ya simu si sahihi.'], 400);
            }

            try {
                $valid = Hash::check((string) $password, (string) $user->password);
            } catch (\Throwable $e) {
                $valid = false;
            }
            if (! $valid) {
                return ApiResponse::json(['error' => 'Neno la siri au namba ya simu si sahihi.'], 400);
            }

            $currentMac = $this->normalizeMac($request->input('mac'))
                ?: $this->normalizeMac($request->session()->get('mac'))
                ?: $this->clientMac($request);

            $explicitRouter = $request->input('router_id')
                ?: $request->query('routerID') ?: $request->query('routerid') ?: $request->query('router_id')
                ?: $request->header('X-Router-ID');
            $currentRouter = $this->resolveRegisteredRouter($explicitRouter)
                ?: $this->resolveRegisteredRouter($request->session()->get('routerID'))
                ?: $this->resolveRegisteredRouter($user->last_router_id ?? null);

            if (! $currentMac && $currentRouter) {
                $currentMac = $this->resolveRouterMac($currentRouter, $request->ip(), 3);
            }

            $currentLocation = $request->session()->get('location') ?: $this->locationFor($currentRouter);
            $currentIp = $request->ip();

            // Never persist a fabricated MAC or an unregistered router id.
            $bindMac = $currentMac ?: $this->normalizeMac($user->mac_address ?? null);
            $bindRouter = $currentRouter ?: null;

            DB::table('users')->where('id', $user->id)->update([
                'mac_address' => $bindMac,
                'last_router_id' => $bindRouter,
                'location' => $currentLocation,
                'ip_address' => $currentIp,
                'updated_at' => now(),
            ]);

            if ($bindRouter && $this->claimPendingGrants((int) $user->id)) {
                $fresh = DB::table('users')->where('id', $user->id)->first();
                if ($fresh) {
                    $user = $fresh;
                }
            }

            $safeUser = $this->publicUser($user);
            $safeUser['mac_address'] = $bindMac;
            $safeUser['last_router_id'] = $bindRouter;
            $safeUser['location'] = $currentLocation;
            $safeUser['ip_address'] = $currentIp;

            // Regenerates the session id and restores the device/router binding.
            LegacySession::login($request, 'customer', $safeUser, [
                'mac' => $bindMac,
                'routerID' => $bindRouter,
                'location' => $currentLocation,
                'onWifi' => (bool) $bindRouter,
                'ssidshow' => $request->session()->get('ssidshow') ?: $this->ssidFromRequest($request),
                'clientIP' => $currentIp,
                'userAgent' => $request->header('User-Agent'),
            ]);

            // Best effort: reconnect an entitled device after login.
            if ($bindMac && $bindRouter) {
                $usageUntil = ! empty($user->usage_until) ? $this->parseDate($user->usage_until) : null;
                if ($usageUntil && $usageUntil->gt(now())) {
                    $this->grantRouter($bindMac, $bindRouter, max(0, $usageUntil->diffInSeconds(now())), 0, false);
                } elseif ((float) ($user->free_bytes ?? 0) > 0) {
                    $this->grantRouter($bindMac, $bindRouter, 0, (float) $user->free_bytes, true);
                }
            }

            return ApiResponse::json(['success' => true]);
        } catch (\Throwable $e) {
            report($e);

            return ApiResponse::fail('Tatizo limetokea. Tafadhali jaribu tena.', 500);
        }
    }

    public function register(Request $request): JsonResponse
    {
        try {
            $name = $request->input('name');
            $rawPhone = $request->input('phone_number');
            $password = $request->input('password');
            $passwordConfirm = $request->input('password_confirm')
                ?? $request->input('passwordConfirm')
                ?? $request->input('password_confirmation');
            $phone = $this->normalizePhone($rawPhone);

            if (! $name || mb_strlen((string) $name) < 3 || mb_strlen((string) $name) > 255) {
                return ApiResponse::json(['error' => 'Jina linapaswa kuwa na herufi kati ya 3 hadi 255.'], 400);
            }
            if (! $phone || ! preg_match('/^0[0-9]{9}$/', $phone)) {
                return ApiResponse::json(['error' => 'Namba ya simu inapaswa kuwa na tarakimu 10 na kuanza na 0.'], 400);
            }
            if (! $password || strlen((string) $password) < 6) {
                return ApiResponse::json(['error' => 'Neno siri linapaswa kuwa na angalau tarakimu 6.'], 400);
            }
            if ($password !== $passwordConfirm) {
                return ApiResponse::json(['error' => 'Neno siri hazifanani.'], 400);
            }

            $existing = DB::table('users')->where('phone_number', $phone)->where('role', 'customer')->first(['id', 'is_active']);
            if (! $existing && $rawPhone) {
                $existing = DB::table('users')->where('phone_number', $rawPhone)->where('role', 'customer')->first(['id', 'is_active']);
            }
            if ($existing) {
                return ApiResponse::json([
                    'error' => 'Namba hii ya simu imesajiliwa tayari. Tafadhali ingia au tumia namba tofauti.',
                ], 400);
            }

            $hashed = Hash::make((string) $password, ['cost' => 12]);

            $routerId = $this->resolveRegisteredRouter($request->input('router_id') ?: $this->routerIdFromRequest($request));
            $clientMac = $this->normalizeMac($request->input('mac'))
                ?: $this->clientMac($request)
                ?: $this->normalizeMac($request->session()->get('mac'));
            if (! $clientMac && $routerId) {
                $clientMac = $this->resolveRouterMac($routerId, $request->ip(), 12);
            }
            $location = $routerId ? $this->locationFor($routerId) : ($request->session()->get('location') ?: null);
            $clientIp = $request->ip();

            $settings = DB::table('system_settings')
                ->whereIn('setting_key', ['default_free_data_mb', 'free_data_per_user'])
                ->pluck('setting_value', 'setting_key')
                ->all();
            // Legacy free_data_per_user is stored in BYTES and wins when present.
            $legacy = $settings['free_data_per_user'] ?? null;
            if ($legacy !== null && $legacy !== '') {
                $freeBytes = (int) $legacy;
            } elseif (! empty($settings['default_free_data_mb'])) {
                $freeBytes = (int) $settings['default_free_data_mb'] * self::MIB;
            } else {
                $freeBytes = 10 * self::MIB;
            }

            $timestamp = now()->toDateTimeString();
            $id = DB::table('users')->insertGetId([
                'name' => $name,
                'phone_number' => $phone,
                'password' => $hashed,
                'role' => 'customer',
                'package' => 'KARIBU HOT BANDO',
                'mac_address' => $clientMac,
                'location' => $location,
                'last_router_id' => $routerId,
                'ip_address' => $clientIp,
                'moneyspent' => 0,
                'usage_start' => $timestamp,
                'usage_until' => $timestamp,
                'free_bytes' => $freeBytes,
                'last_free_used' => null,
                'is_active' => 1,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            $user = DB::table('users')->where('id', $id)->first();
            LegacySession::login($request, 'customer', $this->publicUser($user), [
                'mac' => $clientMac,
                'routerID' => $routerId,
                'location' => $location,
                'onWifi' => (bool) $routerId,
                'ssidshow' => $request->session()->get('ssidshow') ?: $this->ssidFromRequest($request),
                'clientIP' => $clientIp,
                'userAgent' => $request->header('User-Agent'),
            ]);

            $routerConnected = false;
            if ($clientMac && $routerId) {
                $routerConnected = $this->grantRouter($clientMac, $routerId, 0, $freeBytes, false);
            }

            return ApiResponse::json([
                'success' => true,
                'routerConnected' => $routerConnected,
                'mac' => $clientMac,
                'routerID' => $routerId,
                'freeBytes' => $freeBytes,
            ]);
        } catch (\Throwable $e) {
            report($e);
            if (str_contains(strtolower($e->getMessage()), 'duplicate') || (string) $e->getCode() === '23000') {
                return ApiResponse::json([
                    'error' => 'Namba hii ya simu imesajiliwa tayari. Tafadhali ingia au tumia namba tofauti.',
                ], 400);
            }

            return ApiResponse::fail('Tatizo limetokea wakati wa usajili. Tafadhali jaribu tena.', 500);
        }
    }

    // ------------------------------------------------------------------
    // Connection / disconnection
    // ------------------------------------------------------------------

    public function connect(Request $request): JsonResponse
    {
        try {
            $user = $this->sessionUser($request);
            if (! $user) {
                return ApiResponse::json([
                    'success' => false,
                    'message' => 'Umelazimika kuingia kwenye akaunti yako.',
                ]);
            }

            $routerId = $this->resolveRegisteredRouter($request->session()->get('routerID'))
                ?: $this->resolveRegisteredRouter($user['last_router_id'] ?? null);

            $mac = $this->normalizeMac($request->session()->get('mac'));
            if (! $mac && $routerId) {
                $mac = $this->resolveRouterMac($routerId, $request->ip(), 12);
            }
            if (! $mac) {
                $mac = $this->normalizeMac($user['mac_address'] ?? null);
            }
            if (! $mac || ! $routerId) {
                return ApiResponse::json([
                    'success' => false,
                    'message' => 'Kifaa hiki hakionekani kwenye Mtandao wa Meshline WiFi. Tafadhali unganishe kwenye WiFi ya Meshline WiFi kisha ujaribu tena.',
                ]);
            }

            $this->claimPendingGrants((int) ($user['id'] ?? 0));

            // Re-read so vouchers / packages activated after login are honoured.
            $fresh = DB::table('users')->where('id', $user['id'])->first();
            if ($fresh) {
                $user = array_merge($user, $this->publicUser($fresh));
                $this->putSessionUser($request, $user);
            }

            $now = Carbon::now();
            $uptime = 0;
            $bytes = 0;
            $connectionType = 'none';

            $usageUntil = ! empty($user['usage_until']) ? $this->parseDate($user['usage_until']) : null;
            if ($usageUntil && $usageUntil->gt($now)) {
                $uptime = max(0, $usageUntil->diffInSeconds($now));
                $connectionType = 'premium';
            } elseif ((float) ($user['free_bytes'] ?? 0) > 0) {
                $bytes = (float) $user['free_bytes'];
                $connectionType = 'free';
            } else {
                return ApiResponse::json([
                    'success' => false,
                    'message' => 'Hakuna data iliyobaki. Tafadhali nunua kifurushi au tumia vocha.',
                ]);
            }

            // Persist the binding before contacting the router.
            try {
                DB::table('users')->where('id', $user['id'])->update([
                    'mac_address' => $mac,
                    'last_router_id' => $routerId,
                    'last_free_used' => $now->toDateTimeString(),
                    'updated_at' => $now->toDateTimeString(),
                ]);
                $request->session()->put('mac', $mac);
                $request->session()->put('routerID', $routerId);
                $user['mac_address'] = $mac;
                $user['last_router_id'] = $routerId;
                $this->putSessionUser($request, $user);
            } catch (\Throwable $e) {
                report($e);
            }

            $preserveBytes = ($uptime === 0 && $bytes > 0);
            $routerSuccess = $this->grantRouter($mac, $routerId, $uptime, $bytes, $preserveBytes);

            return ApiResponse::json([
                'success' => $routerSuccess,
                'message' => $routerSuccess
                    ? 'Imefanikiwa kunganishwa kwenye mtandao! Sasa unaweza kutumia internet.'
                    : 'Imeshindikana kuunganishwa kwenye mtandao. Tafadhali jaribu tena.',
                'type' => $connectionType,
                'uptime' => $uptime,
                'bytes' => $bytes,
                'routerConnected' => $routerSuccess,
                'mac' => $routerSuccess ? $mac : null,
                'routerID' => $routerSuccess ? $routerId : null,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return ApiResponse::json([
                'success' => false,
                'message' => 'Imeshindikana kuunganishwa kwenye mtandao.',
                'type' => 'error',
            ]);
        }
    }

    public function disconnect(Request $request): JsonResponse
    {
        try {
            $user = $this->sessionUser($request);
            if (! $user) {
                return ApiResponse::json([
                    'success' => false,
                    'message' => 'Umelazimika kuingia kwenye akaunti yako.',
                ]);
            }

            if (! $this->revokeRouter($user['mac_address'] ?? null, $user['last_router_id'] ?? null)) {
                throw new \RuntimeException('Router disconnect failed');
            }

            return ApiResponse::json([
                'success' => true,
                'message' => 'Umeondolewa kwenye mtandao.',
            ]);
        } catch (\Throwable $e) {
            report($e);

            return ApiResponse::json([
                'success' => false,
                'message' => 'Imeshindikana kukutoka kwenye mtandao.',
            ]);
        }
    }

    public function logout(Request $request): Response
    {
        $this->performLogout($request);

        return redirect('/hotspot');
    }

    public function logoutJson(Request $request): JsonResponse
    {
        $this->performLogout($request);

        return ApiResponse::json(['success' => true, 'message' => 'Logged out successfully']);
    }

    private function performLogout(Request $request): void
    {
        $user = $this->sessionUser($request);
        if ($user) {
            try {
                $this->revokeRouter($user['mac_address'] ?? null, $user['last_router_id'] ?? null);
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $request->session()->flush();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    // ------------------------------------------------------------------
    // Dashboard / usage / notifications
    // ------------------------------------------------------------------

    public function dashboard(Request $request): JsonResponse
    {
        $user = $this->sessionUser($request);
        if (! $user || empty($user['id'])) {
            return ApiResponse::json(['error' => 'Umelazimika kuingia kwenye akaunti yako.'], 401);
        }

        try {
            $fresh = DB::table('users')->where('id', $user['id'])->first();
            if (! $fresh) {
                return ApiResponse::json(['error' => 'Akaunti haipatikani.'], 401);
            }

            $sessionUser = array_merge($this->publicUser($fresh), [
                'mac_address' => ($user['mac_address'] ?? null) ?: $fresh->mac_address,
                'last_router_id' => ($user['last_router_id'] ?? null) ?: $fresh->last_router_id,
            ]);
            $this->putSessionUser($request, $sessionUser);
            $user = $sessionUser;

            $now = Carbon::now();
            $usageUntil = ! empty($user['usage_until']) ? $this->parseDate($user['usage_until']) : null;
            $hasActive = $usageUntil ? $now->lt($usageUntil) : false;

            // Guarded fallback for a SUCCESS payment that never activated.
            if (! $hasActive && (float) ($user['free_bytes'] ?? 0) <= 0) {
                $this->fulfillPendingPayment($user);
                $fresh = DB::table('users')->where('id', $user['id'])->first();
                if ($fresh) {
                    $sessionUser = array_merge($this->publicUser($fresh), [
                        'mac_address' => $user['mac_address'] ?? null,
                        'last_router_id' => $user['last_router_id'] ?? null,
                    ]);
                    $this->putSessionUser($request, $sessionUser);
                    $user = $sessionUser;
                }
            }

            $usageUntil = ! empty($user['usage_until']) ? $this->parseDate($user['usage_until']) : null;
            $isActive = $usageUntil ? $now->lt($usageUntil) : false;
            $remainingSeconds = $isActive ? max(0, $usageUntil->diffInSeconds($now, false)) : 0;
            $freeMB = (float) ($user['free_bytes'] ?? 0) / self::MIB;

            $macAddr = $request->session()->get('mac')
                ?: ($user['mac_address'] ?? null)
                ?: ($user['mac_address'] ?? null);
            $routerId = $request->session()->get('routerID')
                ?: ($user['last_router_id'] ?? null)
                ?: ($user['last_router_id'] ?? null);
            $paidBytesRemaining = (float) ($user['paid_bytes_remaining'] ?? 0);
            $paidDataLimitBytes = (float) ($user['paid_data_limit_bytes'] ?? 0);
            $paidMB = $paidBytesRemaining > 0
                ? number_format($paidBytesRemaining / self::MIB, 2, '.', '')
                : ($paidDataLimitBytes > 0
                    ? number_format($paidDataLimitBytes / self::MIB, 2, '.', '')
                    : 'Unlimited');

            return ApiResponse::json([
                'user' => [
                    'id' => $user['id'] ?? null,
                    'name' => $user['name'] ?? null,
                    'phone_number' => $user['phone_number'] ?? null,
                    'package' => $user['package'] ?? null,
                    'moneyspent' => $user['moneyspent'] ?? null,
                    'free_bytes' => $user['free_bytes'] ?? 0,
                    'paid_bytes_remaining' => $paidBytesRemaining,
                    'paid_data_limit_bytes' => $paidDataLimitBytes,
                    'usage_start' => $user['usage_start'] ?? null,
                    'usage_until' => $user['usage_until'] ?? null,
                    'mac_address' => $macAddr,
                    'last_router_id' => $routerId,
                ],
                'location' => $request->session()->get('location'),
                'routerID' => $routerId,
                'ssidshow' => $request->session()->get('ssidshow'),
                'mac' => $macAddr ?: '00:00:00:00:00:00',
                'mac_address' => $macAddr,
                'last_router_id' => $routerId,
                'clientIP' => $request->session()->get('clientIP') ?: $request->ip(),
                'isActive' => $isActive,
                'remainingSeconds' => (int) round($remainingSeconds),
                'remainingTime' => $this->formatRemainingTime($remainingSeconds),
                'freeMB' => number_format($freeMB, 2, '.', ''),
                'paidMB' => $paidMB,
                'paidBytesRemaining' => $paidBytesRemaining,
                'paidDataLimitBytes' => $paidDataLimitBytes,
                'routerStatus' => $routerId ? 'registered' : 'registered',
                'connectedUsers' => null,
                'routerMessage' => 'Umeunganishwa kwenye mtandao wa Meshline WiFi.',
            ]);
        } catch (\Throwable $e) {
            report($e);

            return ApiResponse::fail('Imeshindikana kupata taarifa za akaunti.', 500);
        }
    }

    public function usage(Request $request): JsonResponse
    {
        $user = $this->sessionUser($request);
        if (! $user || empty($user['id'])) {
            return ApiResponse::json(['error' => 'Umelazimika kuingia kwenye akaunti yako.'], 401);
        }

        try {
            $fresh = DB::table('users')
                ->where('id', $user['id'])
                ->first(['free_bytes', 'paid_bytes_remaining', 'paid_data_limit_bytes', 'usage_until', 'moneyspent', 'package']);
            if (! $fresh) {
                return ApiResponse::json(['error' => 'Akaunti haipatikani.'], 404);
            }

            $this->putSessionUser($request, array_merge($user, $this->publicUser($fresh)));

            $now = Carbon::now();
            $usageUntil = ! empty($fresh->usage_until) ? $this->parseDate($fresh->usage_until) : null;
            $isActive = $usageUntil ? $now->lt($usageUntil) : false;
            $remainingSeconds = $isActive ? max(0, $usageUntil->diffInSeconds($now, false)) : 0;
            $paidBytesRemaining = (float) ($fresh->paid_bytes_remaining ?? 0);
            $paidDataLimitBytes = (float) ($fresh->paid_data_limit_bytes ?? 0);
            $paidMB = $paidBytesRemaining > 0
                ? number_format($paidBytesRemaining / self::MIB, 2, '.', '')
                : ($paidDataLimitBytes > 0
                    ? number_format($paidDataLimitBytes / self::MIB, 2, '.', '')
                    : 'Unlimited');

            return ApiResponse::json([
                'freeMB' => number_format((float) ($fresh->free_bytes ?? 0) / self::MIB, 2, '.', ''),
                'free_bytes' => $fresh->free_bytes ?? 0,
                'paidMB' => $paidMB,
                'paidBytesRemaining' => $paidBytesRemaining,
                'paidDataLimitBytes' => $paidDataLimitBytes,
                'isActive' => $isActive,
                'remainingSeconds' => (int) round($remainingSeconds),
                'remainingTime' => $this->formatRemainingTime($remainingSeconds),
                'package' => $fresh->package,
                'moneyspent' => $fresh->moneyspent,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return ApiResponse::fail('Imeshindikana kupata matumizi.', 500);
        }
    }

    public function notifications(Request $request): JsonResponse
    {
        $user = $this->sessionUser($request);
        if (! $user || empty($user['id'])) {
            return ApiResponse::json(['error' => 'Umelazimika kuingia kwenye akaunti yako.'], 401);
        }

        try {
            $notifications = DB::table('notifications')
                ->where('user_id', $user['id'])
                ->orderByDesc('created_at')
                ->limit(10)
                ->get([
                    'id', 'notification_type', 'title', 'message',
                    'action_url', 'action_text', 'is_read', 'created_at',
                ]);

            return ApiResponse::json(['notifications' => $notifications]);
        } catch (\Throwable $e) {
            report($e);

            return ApiResponse::fail('Imeshindikana kupata ujumbe.', 500);
        }
    }

    public function markNotificationsRead(Request $request): JsonResponse
    {
        $user = $this->sessionUser($request);
        if (! $user || empty($user['id'])) {
            return ApiResponse::json(['error' => 'Umelazimika kuingia kwenye akaunti yako.'], 401);
        }

        try {
            DB::table('notifications')
                ->where('user_id', $user['id'])
                ->where('is_read', 0)
                ->update(['is_read' => 1, 'read_at' => now()]);

            return ApiResponse::json(['success' => true]);
        } catch (\Throwable $e) {
            report($e);

            return ApiResponse::fail('Imeshindikana kusasisha notifications.', 500);
        }
    }

    // ------------------------------------------------------------------
    // Vouchers
    // ------------------------------------------------------------------

    public function voucher(Request $request): JsonResponse
    {
        try {
            $code = $request->input('voucher_code') ?: $request->input('voucher');
            $user = $this->sessionUser($request);

            if (! $user) {
                return ApiResponse::json([
                    'success' => 'error',
                    'message' => 'Umelazimika kuingia kwenye akaunti yako.',
                ], 401);
            }
            if (! $code) {
                return ApiResponse::json([
                    'success' => 'error',
                    'message' => 'Tafadhali ingiza namba ya vocha.',
                ], 400);
            }

            $vouch = DB::table('vouchers as v')
                ->join('packages as p', 'v.package_id', '=', 'p.id')
                ->where('v.voucher_code', $code)
                ->where('v.is_used', 0)
                ->whereRaw('v.expires_at > NOW()')
                ->select('v.*', 'p.duration_hours', 'p.name as package_name', 'p.data_limit_mb')
                ->first();
            if (! $vouch) {
                return ApiResponse::json([
                    'success' => 'error',
                    'message' => 'Voucher sio sahihi, imetumika, au imekwisha muda wake.',
                ], 400);
            }

            $now = Carbon::now();
            $redeemed = DB::table('vouchers')
                ->where('id', $vouch->id)
                ->where('is_used', 0)
                ->update([
                    'is_used' => 1,
                    'used_at' => $now->toDateTimeString(),
                    'used_by' => $user['id'],
                ]);
            if ($redeemed === 0) {
                return ApiResponse::json([
                    'success' => 'error',
                    'message' => 'Voucher imekwisha kutumika na mtu mwingine.',
                ], 400);
            }

            try {
                $entitlement = $this->extendUserPackage((int) $user['id'], (array) $vouch);
            } catch (\Throwable $e) {
                DB::table('vouchers')->where('id', $vouch->id)->update([
                    'is_used' => 0,
                    'used_at' => null,
                    'used_by' => null,
                ]);
                throw $e;
            }

            DB::table('users')->where('id', $user['id'])->update([
                'moneyspent' => DB::raw('moneyspent + '.(float) $vouch->price),
                'updated_at' => $now->toDateTimeString(),
            ]);
            DB::table('payments')->insert([
                'user_id' => $user['id'],
                'package_id' => $vouch->package_id,
                'amount' => $vouch->price,
                'payment_method' => 'voucher',
                'status' => 'completed',
                'activated' => 1,
                'created_at' => $now->toDateTimeString(),
            ]);

            $seller = DB::table('voucher_batches')->where('id', $vouch->batch_id)->first(['created_by']);
            $sellerId = $seller->created_by ?? null;
            if (! empty($vouch->reseller_profit)) {
                // Reseller-priced voucher: full selling price goes to the seller.
                if ($sellerId) {
                    try {
                        $sale = (float) $vouch->price;
                        DB::table('resellers')->where('user_id', $sellerId)->update([
                            'balance' => DB::raw('balance + '.$sale),
                            'total_earned' => DB::raw('total_earned + '.$sale),
                            'total_vouchers_sold' => DB::raw('total_vouchers_sold + 1'),
                        ]);
                        DB::table('transactions')->insert([
                            'user_id' => $sellerId,
                            'transaction_type' => 'commission',
                            'amount' => $sale,
                            'description' => 'Voucher '.$vouch->voucher_code.' redeemed at TZS '.number_format($sale),
                            'status' => 'completed',
                            'created_at' => $now->toDateTimeString(),
                        ]);
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            } elseif ($sellerId) {
                // Standard voucher sold by a reseller: 20% platform commission.
                try {
                    $creator = DB::table('users')->where('id', $sellerId)->first(['role']);
                    if ($creator && $creator->role === 'reseller') {
                        $price = (float) $vouch->price;
                        DB::table('resellers')->where('user_id', $sellerId)->update([
                            'total_vouchers_sold' => DB::raw('total_vouchers_sold + 1'),
                            'total_earned' => DB::raw('total_earned + '.($price * 0.8)),
                        ]);
                        DB::table('transactions')->insert([
                            'user_id' => $sellerId,
                            'transaction_type' => 'commission',
                            'amount' => $price * 0.2,
                            'description' => 'Platform commission 20% on voucher '.$vouch->voucher_code,
                            'status' => 'completed',
                            'created_at' => $now->toDateTimeString(),
                        ]);
                    }
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            $updated = DB::table('users')->where('id', $user['id'])->first();
            $this->putSessionUser($request, $this->publicUser($updated));

            $mac = $this->normalizeMac($updated->mac_address ?? null) ?: $this->normalizeMac($request->session()->get('mac'));
            $routerId = $this->resolveRegisteredRouter($request->session()->get('routerID'))
                ?: $this->resolveRegisteredRouter($updated->last_router_id ?? null);
            if (! $mac && $routerId) {
                $mac = $this->resolveRouterMac($routerId, $request->ip(), 12);
            }

            $routerConnected = false;
            if ($mac && $routerId) {
                $uptime = max(0, $entitlement['usageUntil']->diffInSeconds($now, false));
                $routerConnected = $this->grantRouter($mac, $routerId, $uptime, 0, false);
                if ($routerConnected) {
                    DB::table('users')->where('id', $user['id'])->update([
                        'mac_address' => $mac,
                        'last_router_id' => $routerId,
                        'updated_at' => now(),
                    ]);
                    $request->session()->put('mac', $mac);
                    $request->session()->put('routerID', $routerId);
                    $current = $this->sessionUser($request) ?? [];
                    $current['mac_address'] = $mac;
                    $current['last_router_id'] = $routerId;
                    $this->putSessionUser($request, $current);
                }
            }

            return ApiResponse::json([
                'success' => 'success',
                'message' => $routerConnected
                    ? 'Voucher imefanikiwa! Umefunguliwa kutumia mtandao.'
                    : 'Voucher imehifadhiwa. Bonyeza "Wasiliana na Mtandao" kukamilisha muunganisho.',
                'package' => $vouch->package_name,
                'until' => $this->isoUtc($entitlement['usageUntil']),
                'routerConnected' => $routerConnected,
                'duration' => $this->formatRemainingTime((float) $vouch->duration_hours * 3600),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return ApiResponse::json([
                'success' => 'error',
                'message' => 'Imeshindikana kutumia voucher.',
            ]);
        }
    }

    public function packages(): JsonResponse
    {
        try {
            $packages = DB::table('packages')
                ->where('is_active', 1)
                ->orderBy('duration_hours', 'asc')
                ->get(['id', 'name', 'description', 'price', 'duration_hours']);

            return ApiResponse::json(['packages' => $packages]);
        } catch (\Throwable $e) {
            report($e);

            return ApiResponse::json(['error' => 'Imeshindikana kupata kifurushi.'], 500);
        }
    }

    // ------------------------------------------------------------------
    // Captive detection probes under /api/hotspot (JSON-aware Express routes)
    // ------------------------------------------------------------------

    public function captiveGenerate204(Request $request): Response
    {
        return response('', LegacySession::user($request) ? 204 : 200);
    }

    public function captiveNcsi(Request $request): Response
    {
        if (! LegacySession::user($request)) {
            return response('Microsoft NCSI', 200, ['Content-Type' => 'text/plain']);
        }

        return response('', 204);
    }

    public function captiveHotspotDetect(Request $request): Response
    {
        if (! LegacySession::user($request)) {
            return response(
                '<HTML><HEAD><TITLE>Success</TITLE></HEAD><BODY>Success</BODY></HTML>',
                200,
                ['Content-Type' => 'text/html']
            );
        }

        return response('', 204);
    }

    public function captiveStatus(Request $request): JsonResponse
    {
        $user = LegacySession::user($request);

        return ApiResponse::json([
            'authenticated' => $user !== null,
            'user' => $user ? [
                'id' => $user['id'] ?? null,
                'name' => $user['name'] ?? null,
                'phone' => $user['phone_number'] ?? null,
            ] : null,
            'timestamp' => gmdate('Y-m-d\TH:i:s.v\Z'),
        ]);
    }

    // ------------------------------------------------------------------
    // Helpers — phone / MAC / router / location
    // ------------------------------------------------------------------

    private function normalizePhone(mixed $raw): ?string
    {
        if (! $raw) {
            return null;
        }
        $phone = Meshline::normalizePhone((string) $raw);

        return $phone !== '' ? $phone : null;
    }

    private function normalizeMac(mixed $raw): ?string
    {
        if (! $raw) {
            return null;
        }
        $mac = strtoupper(trim((string) $raw));
        $mac = str_replace('-', ':', $mac);

        return preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac) ? $mac : null;
    }

    private function clientMac(Request $request): ?string
    {
        if ($request->query('mac')) {
            $mac = $this->normalizeMac($request->query('mac'));
            if ($mac) {
                return $mac;
            }
        }
        if ($request->header('X-Client-MAC')) {
            $mac = $this->normalizeMac($request->header('X-Client-MAC'));
            if ($mac) {
                return $mac;
            }
        }
        $agent = (string) $request->header('User-Agent', '');
        if (stripos($agent, 'mac=') !== false && preg_match('/mac=([A-F0-9:]+)/i', $agent, $match)) {
            $mac = $this->normalizeMac($match[1]);
            if ($mac) {
                return $mac;
            }
        }

        return null;
    }

    /** body router_id -> query -> header -> session -> user.last_router_id */
    private function routerIdFromRequest(Request $request): mixed
    {
        return $request->input('router_id')
            ?: $request->query('routerID') ?: $request->query('routerid') ?: $request->query('router_id')
            ?: $request->header('X-Router-ID')
            ?: $request->session()->get('routerID')
            ?: ($this->sessionUser($request)['last_router_id'] ?? null);
    }

    /** Only registered, active routers can be persisted — never invent an id. */
    private function resolveRegisteredRouter(mixed $id): ?string
    {
        if (! $id) {
            return null;
        }
        try {
            $row = DB::table('mikrotiks')
                ->where('is_active', 1)
                ->where(function ($query) use ($id): void {
                    $query->where('router_id', (string) $id);
                    if (is_numeric($id)) {
                        $query->orWhere('id', (int) $id);
                    }
                })
                ->first(['router_id']);

            return $row ? (string) $row->router_id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function registeredRouterRow(mixed $id): ?object
    {
        if (! $id) {
            return null;
        }
        try {
            return DB::table('mikrotiks as m')
                ->leftJoin('locations as l', 'm.location_id', '=', 'l.id')
                ->where('m.is_active', 1)
                ->where(function ($query) use ($id): void {
                    $query->where('m.router_id', (string) $id);
                    if (is_numeric($id)) {
                        $query->orWhere('m.id', (int) $id);
                    }
                })
                ->select(
                    'm.id', 'm.router_id', 'm.router_name', 'm.status', 'm.ssid',
                    'm.latitude', 'm.longitude',
                    DB::raw("COALESCE(l.name, m.router_name, 'Tanzania') AS location")
                )
                ->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function locationFor(mixed $routerId): string
    {
        if (! $routerId) {
            return 'Tanzania';
        }
        try {
            $row = $this->registeredRouterRow($routerId);
            $location = $row ? (string) ($row->location ?? '') : '';
            if ($location !== '' && $location !== 'Tanzania') {
                return $location;
            }
            $name = strtoupper((string) ($row->router_name ?? '').' '.(string) $routerId);
            foreach (['DAR' => 'Dar es Salaam', 'ARUSHA' => 'Arusha', 'MWANZA' => 'Mwanza',
                'MBEYA' => 'Mbeya', 'DODOMA' => 'Dodoma'] as $needle => $city) {
                if (str_contains($name, $needle)) {
                    return $city;
                }
            }

            return 'Tanzania';
        } catch (\Throwable $e) {
            return 'Tanzania';
        }
    }

    private function ssidFromRequest(Request $request): string
    {
        return (string) ($request->query('ssidshow') ?: $request->query('ssid')
            ?: $request->header('X-SSID') ?: 'Meshline WiFi');
    }

    private function sessionUser(Request $request): ?array
    {
        return LegacySession::user($request);
    }

    private function putSessionUser(Request $request, array $user): void
    {
        $role = (string) ($user['role'] ?? LegacySession::role($request) ?? 'customer');
        $request->session()->put(LegacySession::keyForRole($role), $user);
    }

    private function publicUser(mixed $user): ?array
    {
        if (! $user) {
            return null;
        }
        $array = is_array($user) ? $user : (array) $user;
        foreach (['password', 'reset_token', 'reset_token_expiry', 'verification_code'] as $key) {
            unset($array[$key]);
        }

        return $array;
    }

    private function dummyBcrypt(): void
    {
        try {
            Hash::check('dummy-notreal', self::DUMMY_HASH);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    // ------------------------------------------------------------------
    // Helpers — entitlements
    // ------------------------------------------------------------------

    /** Port of utils/packageEntitlement.js: lock, stack time and data. */
    private function extendUserPackage(int $userId, array $pkg): array
    {
        return DB::transaction(function () use ($userId, $pkg): array {
            $row = DB::table('users')->where('id', $userId)->lockForUpdate()->first([
                'usage_start', 'usage_until', 'paid_bytes_remaining', 'paid_data_limit_bytes',
            ]);
            if (! $row) {
                throw new \RuntimeException("Customer {$userId} was not found for package activation");
            }

            $now = Carbon::now();
            $existing = ! empty($row->usage_until) ? $this->parseDate($row->usage_until) : null;
            $currentlyPaid = $existing && $existing->gt($now);
            $usageStart = $currentlyPaid && ! empty($row->usage_start)
                ? $row->usage_start
                : $now->toDateTimeString();
            $base = $currentlyPaid ? $existing->copy() : $now->copy();
            $usageUntil = $base->addSeconds((int) round(max(0, (float) ($pkg['duration_hours'] ?? 0)) * 3600));

            $packageLimit = max(0, (float) ($pkg['data_limit_mb'] ?? 0)) * self::MIB;
            $paidDataLimit = $packageLimit;
            $paidBytesRemaining = $packageLimit;
            if ($packageLimit > 0 && $currentlyPaid && (float) ($row->paid_data_limit_bytes ?? 0) > 0) {
                $paidDataLimit += (float) $row->paid_data_limit_bytes;
                $paidBytesRemaining += (float) $row->paid_bytes_remaining;
            }

            DB::table('users')->where('id', $userId)->update([
                'package' => $pkg['name'] ?? $pkg['package_name'] ?? null,
                'usage_start' => $usageStart,
                'usage_until' => $usageUntil->toDateTimeString(),
                'paid_data_limit_bytes' => $paidDataLimit,
                'paid_bytes_remaining' => $paidBytesRemaining,
                'updated_at' => now(),
            ]);

            return [
                'usageStart' => $usageStart,
                'usageUntil' => $usageUntil,
                'paidDataLimit' => $paidDataLimit,
                'paidBytesRemaining' => $paidBytesRemaining,
            ];
        });
    }

    /** Port of utils/pendingGrants.js: start gifted packages on first connect. */
    private function claimPendingGrants(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }
        $grants = DB::table('pending_package_grants')
            ->where('user_id', $userId)
            ->where('status', 'pending')
            ->orderBy('id')
            ->get(['id', 'package_id']);
        $claimed = 0;
        foreach ($grants as $grant) {
            try {
                $package = DB::table('packages')->where('id', $grant->package_id)->first();
                if (! $package) {
                    continue;
                }
                $this->extendUserPackage($userId, (array) $package);
                DB::table('pending_package_grants')
                    ->where('id', $grant->id)
                    ->where('status', 'pending')
                    ->update(['status' => 'claimed', 'claimed_at' => now()]);
                $claimed++;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $claimed;
    }

    /**
     * Dashboard-poll fallback: activate the last unprocessed SUCCESS ClickPesa
     * package purchase when the customer has no active time and no prior
     * activated payment. The guard prevents hammering the DB every 15s.
     */
    private function fulfillPendingPayment(array $user): void
    {
        try {
            $userId = (int) ($user['id'] ?? 0);
            if ($userId <= 0) {
                return;
            }
            if (DB::table('payments')->where('user_id', $userId)->where('activated', 1)->exists()) {
                return;
            }

            $tx = DB::table('clickpesa_transactions')
                ->where('user_id', $userId)
                ->where('purpose', 'package_purchase')
                ->whereIn('status', ['SUCCESS', 'SETTLED', 'COMPLETED'])
                ->orderByDesc('id')
                ->first();
            if (! $tx || ! empty($tx->processed) || empty($tx->order_reference)) {
                return;
            }

            $guardKey = 'hotspot:fulfillment:'.$tx->order_reference;
            try {
                $claimed = Cache::add($guardKey, 1, now()->addMinutes(30));
            } catch (\Throwable $e) {
                $claimed = true;
            }
            if (! $claimed) {
                return;
            }

            $class = \App\Services\Payment\PaymentFulfillment::class;
            if (! class_exists($class)) {
                return;
            }
            $options = ['amount' => $tx->amount ?? 0, 'currency' => 'TZS'];
            foreach (['fulfillOrder', 'fulfill', 'processOrder'] as $method) {
                if (! method_exists($class, $method)) {
                    continue;
                }
                try {
                    $reflection = new \ReflectionMethod($class, $method);
                    if ($reflection->isStatic()) {
                        $class::$method($tx->order_reference, $options);
                    } else {
                        app($class)->{$method}($tx->order_reference, $options);
                    }

                    return;
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    // ------------------------------------------------------------------
    // Helpers — router calls (best effort, capped at ~12 seconds)
    // ------------------------------------------------------------------

    private function grantRouter(mixed $mac, mixed $routerId, float $uptime, float $bytes, bool $preserve = false): bool
    {
        if (! $mac || ! $routerId) {
            return false;
        }

        return (bool) $this->withRouterTimeout(function () use ($mac, $routerId, $uptime, $bytes, $preserve): bool {
            /** @var RouterManager $manager */
            $manager = app(RouterManager::class);
            $row = $manager->row($routerId);
            if (! $row) {
                return false;
            }
            $session = $manager->connect($row);
            $limits = [
                'uptime_seconds' => $uptime,
                'bytes' => $bytes,
                'data_limit_bytes' => $bytes,
                'preserve_bytes' => $preserve,
            ];
            $result = $manager->driverFor($row)->grantAccess($session, (string) $mac, $limits, $preserve);

            return is_array($result) ? (bool) ($result['success'] ?? true) : (bool) $result;
        }, false);
    }

    private function revokeRouter(mixed $mac, mixed $routerId): bool
    {
        if (! $mac || ! $routerId) {
            return false;
        }

        return (bool) $this->withRouterTimeout(function () use ($mac, $routerId): bool {
            /** @var RouterManager $manager */
            $manager = app(RouterManager::class);
            $row = $manager->row($routerId);
            if (! $row) {
                return false;
            }
            $session = $manager->connect($row);

            return (bool) $manager->driverFor($row)->revokeAccess($session, (string) $mac);
        }, false);
    }

    /** Resolve the station MAC from the router's active sessions — never invent one. */
    private function resolveRouterMac(mixed $routerId, mixed $ip, int $seconds = 12): ?string
    {
        if (! $routerId || ! $ip) {
            return null;
        }

        return $this->withRouterTimeout(function () use ($routerId, $ip): ?string {
            /** @var RouterManager $manager */
            $manager = app(RouterManager::class);
            $row = $manager->row($routerId);
            if (! $row) {
                return null;
            }
            $session = $manager->connect($row);
            $rows = $manager->driverFor($row)->activeSessions($session);
            $address = preg_replace('/^::ffff:/', '', (string) $ip);
            foreach ($rows as $candidate) {
                $candidate = (array) $candidate;
                if (($candidate['address'] ?? '') === $address && ! empty($candidate['mac-address'])) {
                    return $this->normalizeMac($candidate['mac-address']);
                }
            }

            return null;
        }, null, $seconds);
    }

    /**
     * Hard wall-clock guard around router I/O. Drivers are expected to enforce
     * their own timeouts too; this is a backstop for the legacy 12s budget.
     */
    private function withRouterTimeout(callable $fn, mixed $fallback, int $seconds = 12): mixed
    {
        $canAlarm = function_exists('pcntl_async_signals') && function_exists('pcntl_alarm')
            && function_exists('pcntl_signal') && function_exists('pcntl_signal_get_handler')
            && defined('SIGALRM');
        $previous = null;
        if ($canAlarm) {
            $previous = pcntl_signal_get_handler(SIGALRM);
            pcntl_async_signals(true);
            pcntl_signal(SIGALRM, function (): void {
                throw new \RuntimeException('Router operation timed out');
            });
            pcntl_alarm(max(1, $seconds));
        }

        try {
            return $fn();
        } catch (\Throwable $e) {
            report($e);

            return $fallback;
        } finally {
            if ($canAlarm) {
                pcntl_alarm(0);
                pcntl_signal(SIGALRM, $previous);
            }
        }
    }

    // ------------------------------------------------------------------
    // Helpers — time formatting
    // ------------------------------------------------------------------

    /** Mirrors `new Date(value)` + isNaN check in the Node controller. */
    private function parseDate(mixed $value): ?Carbon
    {
        if (! $value) {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function isoUtc(Carbon $date): string
    {
        return $date->copy()->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    private function formatRemainingTime(float $seconds): string
    {
        if ($seconds <= 0) {
            return 'Hakuna muda uliobaki';
        }
        $days = (int) floor($seconds / 86400);
        $hours = (int) floor(fmod($seconds, 86400) / 3600);
        $minutes = (int) floor(fmod($seconds, 3600) / 60);

        if ($days > 0) {
            return "{$days} siku {$hours} masaa";
        }
        if ($hours > 0) {
            return "{$hours} masaa {$minutes} dakika";
        }
        if ($minutes > 0) {
            return "{$minutes} dakika";
        }

        return 'Chini ya dakika moja';
    }
}
