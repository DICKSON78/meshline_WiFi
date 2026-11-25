<?php

use App\Http\Controllers\Router\FleetController;
use Illuminate\Support\Facades\Route;

/** routes/routerFleet.js — mounted at both /api/router-fleet and /api/admin/router-fleet. */
$registerFleetRoutes = function (string $prefix): void {
    Route::middleware('role:admin')->prefix($prefix)->group(function () {
        Route::get('/collectors', [FleetController::class, 'collectors']);
        Route::get('/routers', [FleetController::class, 'routers']);
        Route::post('/detect', [FleetController::class, 'detect']);
        Route::post('/mikrotik', [FleetController::class, 'mikrotik']);
        Route::post('/register', [FleetController::class, 'register']);
    });
};

$registerFleetRoutes('router-fleet');
$registerFleetRoutes('admin/router-fleet');
