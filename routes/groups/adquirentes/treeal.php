<?php

use App\Http\Controllers\Api\CallbackController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rotas da Adquirente Treeal/ONZ
|--------------------------------------------------------------------------
*/

// Webhook para receber notificações da Treeal/ONZ
Route::post('treeal/webhook', [CallbackController::class, 'webhookTreeal'])
    ->middleware(['validate.webhook', 'throttle:30,1']);
