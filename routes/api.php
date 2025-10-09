<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\BilletController;
use App\Http\Controllers\Api\CallbackController;
use App\Http\Controllers\Api\NotificationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\SaqueController;
use App\Http\Controllers\Api\DepositController;
use App\Http\Controllers\Api\Adquirentes\PixupController;
use App\Http\Controllers\Api\Adquirentes\BSPayController;
use App\Http\Controllers\Api\Adquirentes\AsaasController;
use App\Http\Controllers\Api\Adquirentes\PrimePay7Controller;
use App\Http\Controllers\Api\Adquirentes\XDPagController;
use Illuminate\Support\Facades\Artisan;

/* AUTHENTICATION ROUTES */
Route::options('auth/login', function () {
    return response('', 200)
        ->header('Access-Control-Allow-Origin', '*')
        ->header('Access-Control-Allow-Methods', 'POST, GET, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
});
Route::options('auth/verify-2fa', function () {
    return response('', 200)
        ->header('Access-Control-Allow-Origin', '*')
        ->header('Access-Control-Allow-Methods', 'POST, GET, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
});
Route::post('auth/login', [AuthController::class, 'login']);
Route::post('auth/verify-2fa', [AuthController::class, 'verify2FA']);
Route::middleware('auth:sanctum')->group(function () {
    Route::get('auth/verify', [AuthController::class, 'verifyToken']);
    Route::post('auth/logout', [AuthController::class, 'logout']);
});

/* USER ROUTES */
Route::options('balance', function () {
    return response('', 200)->header('Access-Control-Allow-Origin', '*');
});
Route::options('transactions', function () {
    return response('', 200)->header('Access-Control-Allow-Origin', '*');
});
Route::options('transactions/{id}', function () {
    return response('', 200)->header('Access-Control-Allow-Origin', '*');
});
Route::options('user/profile', function () {
    return response('', 200)->header('Access-Control-Allow-Origin', '*');
});
Route::options('pix/generate-qr', function () {
    return response('', 200)->header('Access-Control-Allow-Origin', '*');
});
Route::options('pix/withdraw', function () {
    return response('', 200)->header('Access-Control-Allow-Origin', '*');
});
Route::options('statement', function () {
    return response('', 200)->header('Access-Control-Allow-Origin', '*');
});
Route::options('notifications', function () {
    return response('', 200)->header('Access-Control-Allow-Origin', '*');
});
Route::options('notifications/register-token', function () {
    return response('', 200)->header('Access-Control-Allow-Origin', '*');
});
Route::options('notifications/{id}/read', function () {
    return response('', 200)->header('Access-Control-Allow-Origin', '*');
});
Route::options('notifications/mark-all-read', function () {
    return response('', 200)->header('Access-Control-Allow-Origin', '*');
});
Route::options('notifications/stats', function () {
    return response('', 200)->header('Access-Control-Allow-Origin', '*');
});

Route::middleware(['check.token.secret'])->group(function () {
    Route::get('balance', [UserController::class, 'getBalance']);
    Route::get('transactions', [UserController::class, 'getTransactions']);
    Route::get('transactions/{id}', [UserController::class, 'getTransactionById']);
    Route::get('user/profile', [UserController::class, 'getProfile']);
    Route::post('pix/generate-qr', [UserController::class, 'generatePixQR']);
    Route::post('pix/withdraw', [UserController::class, 'makePixWithdraw']);
    Route::get('statement', [UserController::class, 'getStatement']);
    Route::get('user/real-data', [UserController::class, 'getRealData']);
    
    // Rotas de notificações
    Route::post('notifications/register-token', [NotificationController::class, 'registerToken']);
    Route::get('notifications', [NotificationController::class, 'getNotifications']);
    Route::post('notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::post('notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);
    Route::get('notifications/stats', [NotificationController::class, 'getStats']);
    Route::post('notifications/deactivate-token', [NotificationController::class, 'deactivateToken']);
});

Route::get('/link-storage', function (Request $request) {
    // Verificar se está em ambiente local e se o usuário está autenticado
    if (!app()->environment('local') || !auth()->check()) {
        abort(403, 'Acesso não autorizado.');
    }
    
    $action = $request->get('action');
    if($action == 'migrate'){
        Artisan::call('migrate');
    } elseif($action == 'storage'){
        Artisan::call('storage:unlink');
        Artisan::call('storage:link');
    }
    
    return redirect('/');
})->middleware('auth:sanctum');


Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

/* PIX */
Route::middleware(['check.token.secret', 'throttle:60,1'])->post('wallet/deposit/payment', [DepositController::class, 'makeDeposit']);
Route::middleware(['check.token.secret', 'check.allowed.ip', 'throttle:30,1'])->post('pixout', [SaqueController::class, 'makePayment']);
Route::middleware('throttle:20,1')->post('status', [DepositController::class, 'statusDeposito']);

/* CARTÃO */
Route::middleware(['check.token.secret', 'throttle:60,1'])->post('card/payment', [\App\Http\Controllers\Api\CardPaymentController::class, 'createPayment']);
Route::middleware(['check.token.secret', 'throttle:60,1'])->get('card/payment/{transactionId}', [\App\Http\Controllers\Api\CardPaymentController::class, 'getPaymentStatus']);
Route::post('card/webhook', [\App\Http\Controllers\Api\CardPaymentController::class, 'webhook']);

/* BOLETO */
Route::middleware(['check.token.secret', 'throttle:5,1'])->post('billet/charge', [BilletController::class, 'charge']);

/* PIXUP CALLBACKS */
Route::post('pixup/callback/deposit', [PixupController::class, 'callbackDeposit'])->middleware(['validate.webhook', 'throttle:30,1']);
Route::post('pixup/callback/withdraw', [PixupController::class, 'callbackWithdraw'])->middleware(['validate.webhook', 'throttle:30,1']);
Route::post('pixup/test', [PixupController::class, 'testCallback'])->middleware(['validate.webhook', 'throttle:10,1']);

/* WOOVI CALLBACKS */
Route::post('woovi/callback', [CallbackController::class, 'callbackWoovi'])->middleware(['validate.webhook', 'throttle:30,1']);
Route::post('woovi/callback/withdraw', [CallbackController::class, 'callbackWooviWithdraw'])->middleware(['validate.webhook', 'throttle:30,1']);

/* BSPAY CALLBACKS */
Route::post('bspay/callback/deposit', [BSPayController::class, 'callbackDeposit'])->middleware(['validate.webhook', 'throttle:30,1']);
Route::post('bspay/callback/withdraw', [BSPayController::class, 'callbackWithdraw'])->middleware(['validate.webhook', 'throttle:30,1']);
Route::post('bspay/test', [BSPayController::class, 'testCallback'])->middleware(['validate.webhook', 'throttle:10,1']);

/* ASAAS CALLBACKS */
Route::post('asaas/callback/deposit', [AsaasController::class, 'callbackDeposit'])->middleware(['validate.webhook', 'throttle:30,1']);
Route::post('asaas/callback/withdraw', [AsaasController::class, 'callbackWithdraw'])->middleware(['validate.webhook', 'throttle:30,1']);
Route::post('asaas/test', [AsaasController::class, 'testCallback'])->middleware(['validate.webhook', 'throttle:10,1']);

/* PRIMEPAY7 CALLBACKS */
Route::post('primepay7/callback/deposit', [PrimePay7Controller::class, 'callbackDeposit'])->middleware(['validate.webhook', 'throttle:30,1']);
Route::post('primepay7/callback/withdraw', [PrimePay7Controller::class, 'callbackWithdraw'])->middleware(['validate.webhook', 'throttle:30,1']);
Route::post('primepay7/callback', [PrimePay7Controller::class, 'callbackUnified'])->middleware(['validate.webhook', 'throttle:30,1']);
Route::post('primepay7/webhook', [PrimePay7Controller::class, 'webhook'])->middleware(['validate.webhook', 'throttle:30,1']);

/* XDPAG CALLBACKS */
Route::post('xdpag/callback/deposit', [XDPagController::class, 'callbackDeposit'])->middleware(['validate.webhook', 'throttle:30,1']);
Route::post('xdpag/callback/withdraw', [XDPagController::class, 'callbackWithdraw'])->middleware(['validate.webhook', 'throttle:30,1']);
Route::post('xdpag/test', [XDPagController::class, 'testCallback'])->middleware(['validate.webhook', 'throttle:10,1']);