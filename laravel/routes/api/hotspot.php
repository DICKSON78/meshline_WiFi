<?php

use App\Http\Controllers\HotspotController;
use Illuminate\Support\Facades\Route;

// Port of routes/hotspotApi.js. routes/web.php supplies only the `api`
// prefix, so the legacy `/api/hotspot` prefix is applied here.
Route::prefix('hotspot')->group(function (): void {
    // Device session can be established outside WiFi — it is what proves WiFi.
    Route::get('/session', [HotspotController::class, 'session']);

    // Login / registration stay open (CSRF-exempt in bootstrap/app.php).
    Route::post('/login', [HotspotController::class, 'login']);
    Route::post('/register', [HotspotController::class, 'register']);

    // requireWifi middleware mirrors the legacy requireWifi() guard.
    Route::middleware('wifi')->group(function (): void {
        Route::post('/connect', [HotspotController::class, 'connect']);
        Route::post('/disconnect', [HotspotController::class, 'disconnect']);

        Route::post('/voucher', [HotspotController::class, 'voucher']);
        Route::post('/voucher/redeem', [HotspotController::class, 'voucher']);

        Route::get('/dashboard', [HotspotController::class, 'dashboard']);
        Route::get('/usage', [HotspotController::class, 'usage']);
    });

    // Active hotspot packages (the legacy listing orders by duration).
    Route::get('/packages', [HotspotController::class, 'packages']);

    // Notification views require a logged-in customer (no WiFi requirement).
    Route::middleware('role:customer')->group(function (): void {
        Route::get('/notifications', [HotspotController::class, 'notifications']);
        Route::post('/notifications/read', [HotspotController::class, 'markNotificationsRead']);
    });

    // Logout works whether or not the session is still valid.
    Route::get('/logout', [HotspotController::class, 'logout']);
    Route::post('/logout', [HotspotController::class, 'logoutJson']);

    // Captive probes inside the hotspot API (same behaviour as the root probes,
    // but without the onWifi/package fields in /status).
    Route::get('/captive/generate_204', [HotspotController::class, 'captiveGenerate204']);
    Route::get('/captive/ncsi.txt', [HotspotController::class, 'captiveNcsi']);
    Route::get('/captive/hotspotdetect.html', [HotspotController::class, 'captiveHotspotDetect']);
    Route::get('/captive/status', [HotspotController::class, 'captiveStatus']);
});
