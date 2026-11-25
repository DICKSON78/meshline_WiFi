<?php

use App\Http\Controllers\Admin\LedgerController;
use Illuminate\Support\Facades\Route;

/*
 * Registered first (alphabetical glob order in routes/web.php): these paths
 * shadow the older admin-legacy implementations of /payments, /withdrawals,
 * /withdrawal-stats and /clickpesa-balance.
 */
Route::prefix('admin')->middleware(['role:admin', 'throttle:300,1'])->group(function (): void {
    Route::get('/payments/ledger', [LedgerController::class, 'paymentsLedger']);
    Route::get('/payments/webhook-events', [LedgerController::class, 'webhookEvents']);
    Route::get('/payments', [LedgerController::class, 'payments']);
    Route::get('/clickpesa-balance', [LedgerController::class, 'clickpesaBalance']);

    Route::get('/withdrawals', [LedgerController::class, 'withdrawals']);
    Route::get('/withdrawal-stats', [LedgerController::class, 'withdrawalStats']);
    Route::post('/withdrawals/{id}/{action}', [LedgerController::class, 'processWithdrawal'])
        ->where('action', 'approve|reject');
});
