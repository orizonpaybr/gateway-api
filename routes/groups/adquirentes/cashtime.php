<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\CallbackController;


Route::post('cashtime/callback/deposit', [CallbackController::class, 'callbackDeposit'])->middleware(['validate.webhook', 'throttle:30,1']);
Route::post('cashtime/callback/withdraw', [CallbackController::class, 'callbackWithdraw'])->middleware(['validate.webhook', 'throttle:30,1']);
