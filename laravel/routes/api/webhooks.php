<?php

use App\Http\Controllers\Payment\ClickPesaWebhookController;
use Illuminate\Support\Facades\Route;

// New idempotent ClickPesa event processor (raw body is read in the controller
// for HMAC verification). Duplicate events answer {status:'received',duplicate:true}.
Route::post('/webhooks/clickpesa', [ClickPesaWebhookController::class, 'handleWebhook'])
    ->middleware('webhook.throttle');
