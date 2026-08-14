<?php

use App\Http\Controllers\Api\FyhubContasWebhookController;
use App\Http\Controllers\Api\FyhubWebhookController;
use Illuminate\Support\Facades\Route;

// API QRCode (cash-in): o BACEN/fyhub faz POST em {webhookUrl}/pix. Registrar
// webhookUrl = {APP_URL}/fyhub/webhook cai em /fyhub/webhook/pix — aceitar os dois.
Route::post('fyhub/webhook', [FyhubWebhookController::class, 'handle'])->middleware(['throttle:fyhub-webhook']);
Route::post('fyhub/webhook/pix', [FyhubWebhookController::class, 'handle'])->middleware(['throttle:fyhub-webhook']);

Route::post('fyhub/contas/webhook', [FyhubContasWebhookController::class, 'handle'])
    ->middleware(['throttle:fyhub-contas-webhook']);
