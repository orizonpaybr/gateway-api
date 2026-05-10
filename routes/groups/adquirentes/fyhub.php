<?php

use App\Http\Controllers\Api\FyhubContasWebhookController;
use App\Http\Controllers\Api\FyhubWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('fyhub/webhook', [FyhubWebhookController::class, 'handle'])->middleware(['throttle:fyhub-webhook']);

Route::post('fyhub/contas/webhook', [FyhubContasWebhookController::class, 'handle'])
    ->middleware(['throttle:fyhub-contas-webhook']);
