<?php

use App\Http\Controllers\Router\AutoDetectController;
use App\Http\Controllers\Router\MikrotikSetupController;
use App\Http\Controllers\Router\SetupWizardController;
use Illuminate\Support\Facades\Route;

// routes/setupWizard.js
Route::middleware('role:admin')->prefix('setup-wizard')->group(function () {
    Route::get('/status/{routerID}', [SetupWizardController::class, 'status']);
    Route::get('/instructions/{routerID}/{step}', [SetupWizardController::class, 'instructions']);
    Route::post('/execute/{routerID}/{step}', [SetupWizardController::class, 'execute']);
    Route::post('/quick-setup/{routerID}', [SetupWizardController::class, 'quickSetup']);
    Route::get('/unconfigured', [SetupWizardController::class, 'unconfigured']);
});

// routes/autoDetect.js
Route::middleware('role:admin')->prefix('auto-detect')->group(function () {
    Route::get('/scan', [AutoDetectController::class, 'scan']);
    Route::get('/status', [AutoDetectController::class, 'status']);
    Route::post('/verify', [AutoDetectController::class, 'verify']);
    Route::post('/quick-setup', [AutoDetectController::class, 'quickSetup']);
    Route::put('/move-router', [AutoDetectController::class, 'moveRouter']);
    Route::get('/map-data', [AutoDetectController::class, 'mapData']);
    Route::get('/location-history/{routerID}', [AutoDetectController::class, 'locationHistory']);
});

// routes/mikrotikSetup.js — mounted at /api/admin/mikrotik-setup
Route::middleware('role:admin')->prefix('admin/mikrotik-setup')->group(function () {
    Route::get('/detect', [MikrotikSetupController::class, 'detect']);
    Route::get('/scan', [MikrotikSetupController::class, 'scan']);
    Route::post('/configure', [MikrotikSetupController::class, 'configure']);
    Route::get('/bootstrap', [MikrotikSetupController::class, 'bootstrap']);
    Route::get('/server-status', [MikrotikSetupController::class, 'serverStatus']);
    Route::post('/apply-server', [MikrotikSetupController::class, 'applyServer']);
});
