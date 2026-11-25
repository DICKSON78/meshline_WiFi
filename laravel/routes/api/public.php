<?php

use App\Http\Controllers\PublicController;
use Illuminate\Support\Facades\Route;

// Public website API — contact messages and homepage stats only.
// routes/web.php supplies only the `api` prefix, hence the /api/public prefix.
Route::prefix('public')->group(function (): void {
    Route::post('/contact-message', [PublicController::class, 'submitContactMessage']);
    Route::get('/stats', [PublicController::class, 'getPublicStats']);
});
