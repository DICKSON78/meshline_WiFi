<?php

use App\Http\Controllers\Router\ZeroTouchController;
use Illuminate\Support\Facades\Route;

/**
 * routes/zeroTouch.js — public, CSRF-exempt (see bootstrap/app.php), guarded by
 * ROUTER_REGISTRATION_SECRET / RADIUS_SECRET instead of an admin session.
 */
Route::prefix('zero-touch')->group(function () {
    Route::match(['get', 'post'], '/register', [ZeroTouchController::class, 'register']);
    Route::get('/bootstrap', [ZeroTouchController::class, 'bootstrap']);
    Route::post('/register-json', [ZeroTouchController::class, 'registerJson']);
    Route::match(['get', 'post'], '/provision/{routerID}', [ZeroTouchController::class, 'provision']);
    Route::get('/repair-script/{token}', [ZeroTouchController::class, 'repairScript']);
    Route::get('/repair-status/{routerID}', [ZeroTouchController::class, 'repairStatus']);
    Route::match(['get', 'post'], '/heartbeat', [ZeroTouchController::class, 'heartbeat']);
});
