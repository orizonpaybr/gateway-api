<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Adquirentes\PrimePay7Controller;

// Rotas de callback para PrimePay7
Route::post('primepay7/callback/deposit', [PrimePay7Controller::class, 'callbackDeposit'])->middleware(['validate.webhook', 'throttle:30,1']);
Route::post('primepay7/callback/withdraw', [PrimePay7Controller::class, 'callbackWithdraw'])->middleware(['validate.webhook', 'throttle:30,1']);
Route::post('primepay7/callback', [PrimePay7Controller::class, 'callbackUnified'])->middleware(['validate.webhook', 'throttle:30,1']);
Route::post('primepay7/webhook', [PrimePay7Controller::class, 'webhook'])->middleware(['validate.webhook', 'throttle:30,1']);
