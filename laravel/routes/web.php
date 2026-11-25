<?php

use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [SpaController::class, 'health']);

// Root-level captive portal probes (registered before the SPA catch-all).
if (file_exists(__DIR__.'/captive.php')) {
    require __DIR__.'/captive.php';
}

Route::prefix('api')->group(function () {
    foreach (glob(__DIR__.'/api/*.php') ?: [] as $file) {
        require $file;
    }
});

Route::get('/assets/{path}', [SpaController::class, 'asset'])->where('path', '.*');
Route::get('/{path}', [SpaController::class, 'distAsset'])
    ->where('path', '.*\.(?:png|jpe?g|gif|svg|ico|webp|json|js|css|map|woff2?|ttf|mp4|webm|wasm)$');
Route::get('/legacy/{path}', [SpaController::class, 'legacyAsset'])->where('path', '.*');

Route::get('/hotspot/dashboard', [SpaController::class, 'hotspotDashboard']);
foreach ([
    'hotspot', 'admin', 'reseller',
    'login', 'reseller/signup', 'apply/reseller',
    'services', 'pricing', 'about', 'contact',
] as $path) {
    Route::get('/'.$path, [SpaController::class, 'index']);
    Route::get('/'.$path.'/{any}', [SpaController::class, 'index'])->where('any', '.*');
}
Route::get('/', [SpaController::class, 'index']);
Route::fallback(fn () => app(SpaController::class)->index());
