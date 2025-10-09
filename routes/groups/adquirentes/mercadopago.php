<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\CallbackController;


Route::post('mercadopago/callback/deposit', [CallbackController::class, 'callbackDepositMercadopago'])->middleware(['validate.webhook', 'throttle:30,1']);
