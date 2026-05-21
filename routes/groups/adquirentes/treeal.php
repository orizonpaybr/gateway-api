<?php

use App\Http\Controllers\Api\TreealWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('treeal/webhook/pix', [TreealWebhookController::class, 'handlePix'])
    ->middleware(['throttle:treeal-webhook']);
