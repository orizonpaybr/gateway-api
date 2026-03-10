<?php

use App\Http\Controllers\Api\CallbackController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rotas da Adquirente HeartPay
|--------------------------------------------------------------------------
*/

// 2000/min ≈ 33 webhooks/s — suporta picos sem 429 (antes 500/min ≈ 8,3/s)
Route::post('heartpay/webhook', [CallbackController::class, 'webhookHeartPay'])
    ->middleware(['ensure.webhook.https', 'validate.webhook', 'throttle:2000,1']);
