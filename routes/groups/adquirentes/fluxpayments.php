<?php

use App\Http\Controllers\Api\FluxPaymentsWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('fluxpayments/webhook', [FluxPaymentsWebhookController::class, 'handle'])
    ->middleware(['throttle:fluxpayments-webhook']);
