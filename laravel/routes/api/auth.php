<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

// Portal / SPA authentication API (loaded under the /api prefix).
Route::get('/csrf', [AuthController::class, 'csrf']);
Route::get('/me', [AuthController::class, 'me']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout']);

// Public package listing from index.js (used by the reseller portal).
Route::get('/packages', [AuthController::class, 'packages']);
