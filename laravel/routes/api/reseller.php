<?php

use App\Http\Controllers\Payment\ResellerController;
use Illuminate\Support\Facades\Route;

Route::post('/reseller/register', [ResellerController::class, 'register']);
Route::post('/reseller/login', [ResellerController::class, 'login']);

Route::middleware('role:reseller')->group(function () {
    Route::get('/reseller/dashboard', [ResellerController::class, 'getDashboard']);
    Route::get('/reseller/earnings', [ResellerController::class, 'getDashboard']);
    Route::get('/reseller/vouchers', [ResellerController::class, 'getVouchers']);
    Route::post('/reseller/vouchers/generate', [ResellerController::class, 'generateVouchers']);
    Route::delete('/reseller/vouchers/{id}', [ResellerController::class, 'deleteVoucher'])->whereNumber('id');
    Route::post('/reseller/topup', [ResellerController::class, 'topUp']);
    Route::get('/reseller/topups', [ResellerController::class, 'getTopups']);
    Route::get('/reseller/withdrawals', [ResellerController::class, 'getWithdrawals']);
    Route::post('/reseller/withdraw', [ResellerController::class, 'requestWithdrawal']);
    Route::delete('/reseller/withdrawals/{id}', [ResellerController::class, 'cancelWithdrawal'])->whereNumber('id');
    Route::get('/reseller/settings', [ResellerController::class, 'getSettings']);
    Route::put('/reseller/settings/profile', [ResellerController::class, 'updateProfile']);
    Route::post('/reseller/settings/change-password', [ResellerController::class, 'changePassword']);
});
