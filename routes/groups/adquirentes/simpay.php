<?php

use App\Http\Controllers\Api\SimpayWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('simpay/webhook', [SimpayWebhookController::class, 'handle'])->middleware(['throttle:simpay-webhook']);
