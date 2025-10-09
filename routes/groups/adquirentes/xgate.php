<?php

use App\Http\Controllers\Api\Adquirentes\XgateController;
use Illuminate\Support\Facades\Route;

Route::post('xgate/callback', [XgateController::class, 'callback'])->middleware(['validate.webhook', 'throttle:30,1']);
