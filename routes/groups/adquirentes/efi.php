<?php

use App\Http\Controllers\Api\BilletController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\CallbackController;


Route::post('efi/callback', [CallbackController::class, 'callbackEfi'])->middleware(['validate.webhook', 'throttle:30,1']);
Route::any('efi/billet/notification', [BilletController::class, 'callbackCharge'])->middleware(['validate.webhook', 'throttle:30,1']);
Route::any('efi/card/notification', [CallbackController::class, 'callbackCard'])->middleware(['validate.webhook', 'throttle:30,1']);
