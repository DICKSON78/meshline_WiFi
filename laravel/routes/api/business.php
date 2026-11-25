<?php

use App\Http\Controllers\Admin\BusinessController;
use Illuminate\Support\Facades\Route;

/** Port of routes/business.js, scoped to internet customers/resellers/packages/reports. */
Route::prefix('admin/business')
    ->middleware(['role:admin', 'throttle:300,1'])
    ->group(function (): void {
        // Customers
        Route::get('/customers', [BusinessController::class, 'customers']);
        Route::get('/customers/stats', [BusinessController::class, 'customersStats']);
        Route::get('/customers/{id}', [BusinessController::class, 'customerDetail']);
        Route::post('/customers/{id}/adjust', [BusinessController::class, 'customerAdjust']);

        // Resellers
        Route::get('/resellers', [BusinessController::class, 'resellers']);
        Route::get('/resellers/{id}', [BusinessController::class, 'resellerDetail']);
        Route::post('/resellers/{id}/tier', [BusinessController::class, 'resellerTier']);
        Route::post('/resellers/{id}/status', [BusinessController::class, 'resellerStatus']);

        // Reports
        Route::get('/reports/overview', [BusinessController::class, 'reportsOverview']);
        Route::get('/reports/financial', [BusinessController::class, 'reportsFinancial']);

        // Packages
        Route::get('/packages', [BusinessController::class, 'packages']);
    });
