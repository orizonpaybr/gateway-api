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
use App\Http\Controllers\Api\PixInfracoesController;
use App\Http\Controllers\Api\PixKeyController;

/* AUTHENTICATION ROUTES */
Route::options('auth/login', function () {
    return response('', 200)
        ->header('Access-Control-Allow-Origin', '*')
        ->header('Access-Control-Allow-Methods', 'POST, GET, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
});
Route::options('auth/register', function () {
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
Route::options('auth/validate-registration', function () {
    return response('', 200)
        ->header('Access-Control-Allow-Origin', '*')
        ->header('Access-Control-Allow-Methods', 'POST, GET, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
});
Route::post('auth/login', [AuthController::class, 'login']);
Route::post('auth/register', [AuthController::class, 'register']);
Route::post('auth/verify-2fa', [AuthController::class, 'verify2FA']);
Route::post('auth/validate-registration', [AuthController::class, 'validateRegistrationData']);
Route::post('auth/logout', [AuthController::class, 'logout']); // Logout não precisa de autenticação

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
Route::options('extrato', function () {
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
Route::options('dashboard/stats', function () {
    return response('', 200)->header('Access-Control-Allow-Origin', '*');
});
Route::options('dashboard/interactive-movement', function () {
    return response('', 200)->header('Access-Control-Allow-Origin', '*');
});
Route::options('dashboard/transaction-summary', function () {
    return response('', 200)->header('Access-Control-Allow-Origin', '*');
});
Route::options('pix/infracoes', function () {
    return response('', 200)->header('Access-Control-Allow-Origin', '*');
});
Route::options('qrcodes', function () {
    return response('', 200)->header('Access-Control-Allow-Origin', '*');
});
Route::options('pix/keys', function () {
    return response('', 200)->header('Access-Control-Allow-Origin', '*');
});
Route::options('pix/keys/{id}', function () {
    return response('', 200)->header('Access-Control-Allow-Origin', '*');
});
Route::options('pix/keys/{id}/set-default', function () {
    return response('', 200)->header('Access-Control-Allow-Origin', '*');
});
Route::options('pix/withdraw-with-key', function () {
    return response('', 200)->header('Access-Control-Allow-Origin', '*');
});

// Rotas protegidas com JWT (para frontend)
Route::middleware(['verify.jwt'])->group(function () {
    Route::get('auth/verify', [AuthController::class, 'verifyToken']);
    Route::get('balance', [UserController::class, 'getBalance']);
    Route::get('transactions', [UserController::class, 'getTransactions']);
    Route::get('transactions/{id}', [UserController::class, 'getTransactionById']);
    Route::get('user/profile', [UserController::class, 'getProfile']);
    Route::post('pix/generate-qr', [UserController::class, 'generatePixQR']);
    Route::post('pix/withdraw', [UserController::class, 'makePixWithdraw']);
    Route::get('statement', [UserController::class, 'getStatement']);
    Route::get('extrato', [UserController::class, 'getExtrato']);
    Route::get('user/real-data', [UserController::class, 'getRealData']);
    Route::get('dashboard/stats', [UserController::class, 'getDashboardStats']);
    Route::get('dashboard/interactive-movement', [UserController::class, 'getInteractiveMovement']);
    Route::get('dashboard/transaction-summary', [UserController::class, 'getTransactionSummary']);
    Route::get('gamification/journey', [UserController::class, 'getGamificationData']);
    Route::get('gamification/sidebar', [UserController::class, 'getSidebarGamificationData']);

    // Infrações Pix
    Route::get('pix/infracoes', [PixInfracoesController::class, 'index']);
    Route::get('pix/infracoes/{id}', [PixInfracoesController::class, 'show']);
    
    // Chaves PIX
    Route::get('pix/keys', [PixKeyController::class, 'index']);
    Route::post('pix/keys', [PixKeyController::class, 'store']);
    Route::get('pix/keys/{id}', [PixKeyController::class, 'show']);
    Route::put('pix/keys/{id}', [PixKeyController::class, 'update']);
    Route::delete('pix/keys/{id}', [PixKeyController::class, 'destroy']);
    Route::post('pix/keys/{id}/set-default', [PixKeyController::class, 'setDefault']);
    Route::post('pix/withdraw-with-key', [PixKeyController::class, 'withdraw']);
    
    // QR Codes (Otimizado)
    Route::get('qrcodes', [App\Http\Controllers\Api\QRCodeController::class, 'index']);
    
    // Dashboard Otimizado
    Route::get('dashboard/stats-optimized', [App\Http\Controllers\Api\OptimizedDashboardController::class, 'getDashboardStats']);
    Route::get('dashboard/interactive-movement-optimized', [App\Http\Controllers\Api\OptimizedDashboardController::class, 'getInteractiveMovement']);
    Route::get('dashboard/transaction-summary-optimized', [App\Http\Controllers\Api\OptimizedDashboardController::class, 'getTransactionSummary']);
    
    // Transações Otimizadas
    Route::get('transactions/recent-optimized', [UserController::class, 'getRecentTransactions']);
    
    // Infrações PIX Otimizadas
    Route::get('pix-infracoes-optimized', [PixInfracoesController::class, 'index']);
    
    // Rotas de notificações
    Route::post('notifications/register-token', [NotificationController::class, 'registerToken']);
    Route::get('notifications', [NotificationController::class, 'getNotifications']);
    Route::post('notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::post('notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);
    Route::get('notifications/stats', [NotificationController::class, 'getStats']);
    Route::post('notifications/deactivate-token', [NotificationController::class, 'deactivateToken']);
    
    // Rotas do 2FA
    Route::get('2fa/status', [App\Http\Controllers\TwoFactorAuthController::class, 'status']);
    Route::post('2fa/generate-qr', [App\Http\Controllers\TwoFactorAuthController::class, 'generateQrCode']);
    Route::post('2fa/verify', [App\Http\Controllers\TwoFactorAuthController::class, 'verifyCode']);
    Route::post('2fa/enable', [App\Http\Controllers\TwoFactorAuthController::class, 'enable']);
    Route::post('2fa/disable', [App\Http\Controllers\TwoFactorAuthController::class, 'disable']);
});

// Rotas protegidas com token + secret (para integrações externas e APIs)
Route::middleware(['check.token.secret'])->group(function () {
    // Essas rotas ainda podem usar token + secret para compatibilidade com integrações externas
});

Route::get('/link-storage', function (Request $request) {
    // Verificar se está em ambiente local e se o usuário está autenticado
    if (!app()->environment('local') || !$request->user()) {
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