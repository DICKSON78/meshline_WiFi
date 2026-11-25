<?php

use App\Http\Controllers\Router\RouterController;
use Illuminate\Support\Facades\Route;

// routes/routerRoutes.js — every endpoint requires admin authentication.
Route::middleware('role:admin')->prefix('routers')->group(function () {
    // Literal paths must be registered before /{id}.
    Route::get('/health', [RouterController::class, 'health']);
    Route::get('/status-all', [RouterController::class, 'statusAll']);

    Route::get('/wireguard/peers', [RouterController::class, 'wireGuardPeers']);
    Route::get('/wireguard/server-config', [RouterController::class, 'wireGuardServerConfig']);
    Route::post('/wireguard/generate/{routerID}', [RouterController::class, 'generateWireGuard']);
    Route::get('/wireguard/vps-guide', [RouterController::class, 'vpsGuide']);

    Route::get('/', [RouterController::class, 'index']);
    Route::post('/', [RouterController::class, 'store']);

    Route::get('/{routerID}/status', [RouterController::class, 'liveStatus']);
    Route::get('/{routerID}/sessions', [RouterController::class, 'sessions']);
    Route::get('/{routerID}/hotspot/sessions', [RouterController::class, 'hotspotSessions']);
    Route::get('/{routerID}/hotspot/users', [RouterController::class, 'hotspotUsers']);
    Route::post('/{routerID}/hotspot/kick', [RouterController::class, 'kick']);
    Route::get('/{routerID}/bindings', [RouterController::class, 'bindings']);
    Route::get('/{routerID}/interfaces', [RouterController::class, 'interfaces']);
    Route::get('/{routerID}/logs', [RouterController::class, 'logs']);
    Route::get('/{routerID}/active-users', [RouterController::class, 'activeUsers']);
    Route::get('/{routerID}/details', [RouterController::class, 'details']);
    Route::post('/{routerID}/reboot', [RouterController::class, 'reboot']);
    Route::post('/{routerID}/connect', [RouterController::class, 'connect']);
    Route::post('/{routerID}/omada-test', [RouterController::class, 'omadaTest']);

    Route::get('/{id}', [RouterController::class, 'show']);
    Route::put('/{id}', [RouterController::class, 'update']);
    Route::delete('/{id}', [RouterController::class, 'destroy']);
});
