<?php

use App\Http\Controllers\CaptiveController;
use Illuminate\Support\Facades\Route;

// Root-level captive-portal probes — port of routes/captivePortal.js.
// Required by routes/web.php before the SPA catch-all.

Route::get('/redirect', [CaptiveController::class, 'redirect']);
Route::get('/generate_204', [CaptiveController::class, 'generate204']);
Route::get('/ncsi.txt', [CaptiveController::class, 'ncsi']);
Route::get('/hotspotdetect.html', [CaptiveController::class, 'hotspotdetect']);
Route::get('/status', [CaptiveController::class, 'status']);
Route::get('/logout', [CaptiveController::class, 'logout']);
