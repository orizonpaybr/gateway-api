<?php

use App\Http\Controllers\Api\PaytlerWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('paytler/webhook', [PaytlerWebhookController::class, 'handle'])->middleware(['throttle:paytler-webhook']);
