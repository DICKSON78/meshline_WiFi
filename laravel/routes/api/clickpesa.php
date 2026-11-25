<?php

use App\Http\Controllers\Payment\ClickPesaController;
use Illuminate\Support\Facades\Route;

Route::post('/clickpesa/pay', [ClickPesaController::class, 'initiatePayment']);
Route::post('/clickpesa/preview', [ClickPesaController::class, 'preview']);
Route::get('/clickpesa/status/{orderReference}', [ClickPesaController::class, 'checkStatus']);
Route::get('/clickpesa/confirm/{orderReference}', [ClickPesaController::class, 'confirmPayment']);
Route::post('/clickpesa/webhook', [ClickPesaController::class, 'webhook'])->middleware('webhook.throttle');

Route::middleware('role:admin')->group(function () {
    Route::get('/clickpesa/transactions', [ClickPesaController::class, 'getTransactions']);
    Route::get('/clickpesa/balance', [ClickPesaController::class, 'getBalance']);
});
