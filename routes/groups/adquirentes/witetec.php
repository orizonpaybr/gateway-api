<?php

use App\Http\Controllers\Api\Adquirentes\WitetecController;
use Illuminate\Support\Facades\Route;

Route::post('witetec/callback/deposit', [WitetecController::class, 'callbackDeposit'])->middleware(['validate.webhook', 'throttle:30,1']);
Route::post('witetec/callback/withdraw', [WitetecController::class, 'callbackWithdraw'])->middleware(['validate.webhook', 'throttle:30,1']);