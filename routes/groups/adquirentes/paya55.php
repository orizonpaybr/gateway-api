<?php

use App\Http\Controllers\Api\Paya55WebhookController;
use Illuminate\Support\Facades\Route;

Route::post('paya55/webhook', [Paya55WebhookController::class, 'handle'])
    ->middleware(['throttle:paya55-webhook']);
