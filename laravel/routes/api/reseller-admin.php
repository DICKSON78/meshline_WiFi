<?php

use App\Http\Controllers\Payment\ResellerAdminController;
use Illuminate\Support\Facades\Route;

// Full legacy paths: /api/admin/resellers-v2/... (the api prefix is added by routes/web.php).
// Internet-service reseller management only — no equipment loans/plans/partners.
Route::prefix('/admin/resellers-v2')->middleware('role:admin')->group(function () {
    Route::get('/routers', [ResellerAdminController::class, 'routers']);
    Route::get('/overview', [ResellerAdminController::class, 'overview']);
    Route::post('/resellers', [ResellerAdminController::class, 'createReseller']);
    Route::post('/assign', [ResellerAdminController::class, 'assignRouter']);
    Route::post('/unassign', [ResellerAdminController::class, 'unassignRouter']);
    Route::get('/history/{router_id}', [ResellerAdminController::class, 'history']);
});
