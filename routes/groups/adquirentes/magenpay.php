<?php

use App\Http\Controllers\MagenPayWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('magenpay/webhook', [MagenPayWebhookController::class, 'handle'])
    ->middleware('throttle:120,1');
