<?php

use App\Http\Controllers\Api\UnifiedCallbackController;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Route;

// Callback unificado - redireciona para adquirente correta
Route::post('/callback/', [UnifiedCallbackController::class, 'handleCallback']);
Route::post('/callback/withdraw', [UnifiedCallbackController::class, 'handleWithdrawCallback']);
Route::post('/callback/test', [UnifiedCallbackController::class, 'testCallback']);

// Download de boleto
Route::get('/download-boleto', function () {
    $url = request('url');

    $response = Http::get($url);

    return Response::make($response->body(), 200, [
        'Content-Type' => 'application/pdf',
        'Content-Disposition' => 'attachment; filename="boleto.pdf"',
    ]);
});

// Rotas de adquirentes (webhooks e callbacks específicos)
require __DIR__.'/groups/adquirentes/pagarme.php';
require __DIR__.'/groups/adquirentes/simpay.php';
