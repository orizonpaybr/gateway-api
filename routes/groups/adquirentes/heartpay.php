<?php

use App\Http\Controllers\Api\CallbackController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rotas da Adquirente HeartPay
|--------------------------------------------------------------------------
*/

Route::post('heartpay/webhook', [CallbackController::class, 'webhookHeartPay'])
    ->middleware(['ensure.webhook.https', 'validate.webhook', 'throttle:500,1']);
