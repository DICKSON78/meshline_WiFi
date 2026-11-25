<?php

namespace App\Http\Controllers;

use App\Support\LegacySession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class SpaController extends Controller
{
    private function dist(): string
    {
        return realpath(base_path('../frontend/dist')) ?: base_path('../frontend/dist');
    }

    public function index(): Response
    {
        $file = $this->dist().'/index.html';
        if (! is_file($file)) {
            return response()->json(['error' => 'Frontend not built. Run npm run build in frontend/.'], 404);
        }

        return response()->file($file, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, proxy-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    public function asset(Request $request, string $path): Response
    {
        return $this->safeFile($this->dist(), $path);
    }

    public function distAsset(Request $request, string $path): Response
    {
        return $this->safeFile($this->dist(), $path);
    }

    public function legacyAsset(Request $request, string $path): Response
    {
        return $this->safeFile(base_path('../public'), $path);
    }

    private function safeFile(string $root, string $path): Response
    {
        $realRoot = realpath($root);
        $file = realpath($root.'/'.$path);
        if (! $realRoot || ! $file || ! str_starts_with($file, $realRoot.DIRECTORY_SEPARATOR) || ! is_file($file)) {
            abort(404);
        }

        $mime = match (pathinfo($file, PATHINFO_EXTENSION)) {
            'js' => 'application/javascript',
            'css' => 'text/css',
            'json' => 'application/json',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'mp4' => 'video/mp4',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            default => 'application/octet-stream',
        };

        return response()->file($file, ['Content-Type' => $mime, 'Cache-Control' => 'public, max-age=31536000, immutable']);
    }

    public function hotspotDashboard(Request $request): Response
    {
        if (! LegacySession::id($request)) {
            if ($request->expectsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
                return response()->json([
                    'error' => 'Unauthenticated',
                    'message' => 'Please login to the WiFi portal first',
                    'redirect' => '/hotspot/login',
                ], 401);
            }

            return redirect('/hotspot/login');
        }

        return $this->index();
    }

    public function health(): Response
    {
        $database = 'connected';
        try {
            DB::select('SELECT 1');
        } catch (\Throwable $e) {
            $database = 'disconnected';
        }
        $redis = 'unavailable';
        try {
            if (config('database.redis.client') && class_exists(\Redis::class)) {
                $redis = 'connected';
            }
        } catch (\Throwable $e) {
            $redis = 'disconnected';
        }
        $status = $database === 'connected' ? 'ok' : 'degraded';

        return response()->json([
            'status' => $status,
            'uptime' => (int) (microtime(true) - (defined('LARAVEL_START') ? LARAVEL_START : microtime(true))),
            'database' => $database,
            'redis' => $redis,
        ], $status === 'ok' ? 200 : 503);
    }
}
