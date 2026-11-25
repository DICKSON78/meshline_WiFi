<?php

namespace App\Http\Controllers;

use App\Services\Routers\RouterManager;
use App\Support\ApiResponse;
use App\Support\LegacySession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Root-level captive-portal probes — port of routes/captivePortal.js.
 * These routes are registered before the SPA catch-all in routes/web.php.
 */
class CaptiveController extends Controller
{
    public function redirect(): Response
    {
        return redirect('/hotspot', 302);
    }

    /** Android captive-portal probe. */
    public function generate204(Request $request): Response
    {
        if (! LegacySession::user($request)) {
            return response('', 200);
        }

        return response('', 204);
    }

    /** Windows / NCSI captive-portal probe. */
    public function ncsi(Request $request): Response
    {
        if (! LegacySession::user($request)) {
            return response('Microsoft NCSI', 200, ['Content-Type' => 'text/plain']);
        }

        return response('', 204);
    }

    /** Apple captive-portal probe. */
    public function hotspotdetect(Request $request): Response
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

    public function status(Request $request): JsonResponse
    {
        $user = LegacySession::user($request);

        return ApiResponse::json([
            'authenticated' => $user !== null,
            'user' => $user ? [
                'id' => $user['id'] ?? null,
                'name' => $user['name'] ?? null,
                'phone' => $user['phone_number'] ?? null,
                'package' => $user['package'] ?? null,
            ] : null,
            'onWifi' => (bool) $request->session()->get('onWifi'),
            'timestamp' => gmdate('Y-m-d\TH:i:s.v\Z'),
        ]);
    }

    /** Logout: disconnect from the router (best effort), then flush the session. */
    public function logout(Request $request): JsonResponse
    {
        $user = LegacySession::user($request);
        if ($user && ! empty($user['mac_address']) && ! empty($user['last_router_id'])) {
            try {
                $this->disconnectFromRouter((string) $user['mac_address'], (string) $user['last_router_id']);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $request->session()->flush();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return ApiResponse::json(['success' => true, 'message' => 'Logged out successfully']);
    }

    private function disconnectFromRouter(string $mac, string $routerId): void
    {
        /** @var RouterManager $manager */
        $manager = app(RouterManager::class);
        $row = $manager->row($routerId);
        if (! $row) {
            return;
        }
        $session = $manager->connect($row);
        $manager->driverFor($row)->revokeAccess($session, $mac);
    }
}
