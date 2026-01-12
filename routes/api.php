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
use App\Http\Controllers\Api\AdminTransactionsController;

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
Route::options('auth/change-password', function () {
    return response('', 200)
        ->header('Access-Control-Allow-Origin', '*')
        ->header('Access-Control-Allow-Methods', 'POST, OPTIONS')
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
Route::options('notification-preferences', function () {
    return response('', 200)->header('Access-Control-Allow-Origin', '*');
});
Route::options('notification-preferences/toggle/{type}', function () {
    return response('', 200)->header('Access-Control-Allow-Origin', '*');
});
Route::options('notification-preferences/disable-all', function () {
    return response('', 200)->header('Access-Control-Allow-Origin', '*');
});
Route::options('notification-preferences/enable-all', function () {
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
Route::options('admin/dashboard/stats', function () {
    return response('', 200)->header('Access-Control-Allow-Origin', '*');
});
Route::options('admin/dashboard/users', function () {
    return response('', 200)->header('Access-Control-Allow-Origin', '*');
});
Route::options('admin/dashboard/transactions', function () {
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
    
    // Dashboard Administrativo (apenas para admins - permission == 3)
    // MELHORIA: Usar middleware para evitar código duplicado
    // IMPORTANTE: verify.jwt deve vir antes de ensure.admin para autenticar o usuário
    Route::middleware(['verify.jwt', 'ensure.admin'])->group(function () {
        Route::get('admin/dashboard/stats', [App\Http\Controllers\Api\AdminDashboardController::class, 'getDashboardStats']);
        Route::get('admin/dashboard/transactions', [App\Http\Controllers\Api\AdminDashboardController::class, 'getRecentTransactions']);
        Route::get('admin/dashboard/cache-metrics', [App\Http\Controllers\Api\AdminDashboardController::class, 'getCacheMetrics']);
        
        // CRUD de Usuários (Apenas Admin - ações críticas)
        Route::post('admin/users', [App\Http\Controllers\Api\AdminDashboardController::class, 'storeUser']);
        Route::delete('admin/users/{id}', [App\Http\Controllers\Api\AdminDashboardController::class, 'deleteUser']);
        Route::post('admin/users/{id}/approve', [App\Http\Controllers\Api\AdminDashboardController::class, 'approveUser']);
        Route::post('admin/users/{id}/adjust-balance', [App\Http\Controllers\Api\AdminDashboardController::class, 'adjustBalance']);
        Route::get('admin/users-managers', [App\Http\Controllers\Api\AdminDashboardController::class, 'listManagers']);
        Route::get('admin/pix-acquirers', [App\Http\Controllers\Api\AdminDashboardController::class, 'listPixAcquirers']);
        
        // Rotas de gerenciamento de adquirentes (Admin)
        Route::get('admin/acquirers', [App\Http\Controllers\Api\AdminDashboardController::class, 'listAcquirers']);
        Route::post('admin/acquirers', [App\Http\Controllers\Api\AdminDashboardController::class, 'createAcquirer']);
        Route::put('admin/acquirers/{id}', [App\Http\Controllers\Api\AdminDashboardController::class, 'updateAcquirer'])->where('id', '[0-9]+');
        Route::delete('admin/acquirers/{id}', [App\Http\Controllers\Api\AdminDashboardController::class, 'deleteAcquirer'])->where('id', '[0-9]+');
        Route::post('admin/acquirers/{id}/toggle-status', [App\Http\Controllers\Api\AdminDashboardController::class, 'toggleAcquirerStatus'])->where('id', '[0-9]+');
        
        // Rotas de gerenciamento de saques (Admin)
        // Rotas específicas devem vir antes da rota com {id} para evitar colisão ('stats' sendo interpretado como {id})
        Route::get('admin/withdrawals', [App\Http\Controllers\Api\WithdrawalController::class, 'index']);
        Route::get('admin/withdrawals/stats', [App\Http\Controllers\Api\WithdrawalController::class, 'stats']);
        Route::get('admin/withdrawals/config', [App\Http\Controllers\Api\WithdrawalController::class, 'getConfig']);
        Route::put('admin/withdrawals/config', [App\Http\Controllers\Api\WithdrawalController::class, 'updateConfig']);
        Route::get('admin/withdrawals/{id}', [App\Http\Controllers\Api\WithdrawalController::class, 'show'])->where('id', '[0-9]+');
        Route::post('admin/withdrawals/{id}/approve', [App\Http\Controllers\Api\WithdrawalController::class, 'approve'])->where('id', '[0-9]+');
        Route::post('admin/withdrawals/{id}/reject', [App\Http\Controllers\Api\WithdrawalController::class, 'reject'])->where('id', '[0-9]+');
        
        // Rotas do módulo financeiro (Admin)
        Route::get('admin/financial/transactions', [App\Http\Controllers\Api\FinancialController::class, 'getAllTransactions']);
        Route::get('admin/financial/transactions/stats', [App\Http\Controllers\Api\FinancialController::class, 'getTransactionsStats']);
        Route::get('admin/financial/wallets', [App\Http\Controllers\Api\FinancialController::class, 'getWallets']);
        Route::get('admin/financial/wallets/stats', [App\Http\Controllers\Api\FinancialController::class, 'getWalletsStats']);
        Route::get('admin/financial/deposits', [App\Http\Controllers\Api\FinancialController::class, 'getDeposits']);
        Route::get('admin/financial/deposits/stats', [App\Http\Controllers\Api\FinancialController::class, 'getDepositsStats']);
        Route::put('admin/financial/deposits/{id}/status', [App\Http\Controllers\Api\FinancialController::class, 'updateDepositStatus']);
        Route::get('admin/financial/withdrawals', [App\Http\Controllers\Api\FinancialController::class, 'getWithdrawals']);
        Route::get('admin/financial/withdrawals/stats', [App\Http\Controllers\Api\FinancialController::class, 'getWithdrawalsStats']);
        Route::post('admin/manual-transactions/deposits', [AdminTransactionsController::class, 'storeDeposit']);
        Route::post('admin/manual-transactions/withdrawal', [AdminTransactionsController::class, 'storeWithdrawal']);
        
        // Rotas de configurações do gateway (Admin)
        Route::get('admin/settings', [App\Http\Controllers\Api\GatewaySettingsController::class, 'getSettings']);
        Route::put('admin/settings', [App\Http\Controllers\Api\GatewaySettingsController::class, 'updateSettings']);
        
        // Rotas de gerenciamento de níveis de gamificação (Admin)
        // Observação: o painel atual permite apenas listar e editar níveis existentes.
        Route::get('admin/levels', [App\Http\Controllers\Api\AdminLevelsController::class, 'index']);
        Route::get('admin/levels/{id}', [App\Http\Controllers\Api\AdminLevelsController::class, 'show'])->where('id', '[0-9]+');
        Route::put('admin/levels/{id}', [App\Http\Controllers\Api\AdminLevelsController::class, 'update'])->where('id', '[0-9]+');
        Route::post('admin/levels/toggle-active', [App\Http\Controllers\Api\AdminLevelsController::class, 'toggleActive']);
    });
    
    // Rotas compartilhadas entre Admin (3) e Gerente (2)
    Route::middleware(['verify.jwt', 'ensure.admin_or_manager'])->group(function () {
        // Lista de usuários
        Route::get('admin/dashboard/users', [App\Http\Controllers\Api\AdminDashboardController::class, 'getUsers']);
        
        // Estatísticas de usuários (cards: total, mês, pendentes, banidos)
        Route::get('admin/dashboard/users-stats', [App\Http\Controllers\Api\AdminDashboardController::class, 'getUserStats']);
        
        // Visualizar usuário específico
        Route::get('admin/users/{id}', [App\Http\Controllers\Api\AdminDashboardController::class, 'showUser']);
        
        // Editar usuário
        Route::put('admin/users/{id}', [App\Http\Controllers\Api\AdminDashboardController::class, 'updateUser']);
        
        // Bloquear/desbloquear usuário
        Route::post('admin/users/{id}/toggle-block', [App\Http\Controllers\Api\AdminDashboardController::class, 'toggleBlockUser']);
        
        // Bloquear/desbloquear saque do usuário
        Route::post('admin/users/{id}/toggle-withdraw-block', [App\Http\Controllers\Api\AdminDashboardController::class, 'toggleWithdrawBlock']);
        
        // Ver taxas padrão
        Route::get('admin/default-fees', [App\Http\Controllers\Api\AdminDashboardController::class, 'getDefaultFees']);
        
        // Configurações de afiliado
        Route::post('admin/users/{id}/affiliate-settings', [App\Http\Controllers\Api\AdminDashboardController::class, 'saveAffiliateSettings']);
    });
    
    // Transações Otimizadas
    Route::get('transactions/recent-optimized', [UserController::class, 'getRecentTransactions']);
    
    // Infrações PIX Otimizadas
    Route::get('pix-infracoes-optimized', [PixInfracoesController::class, 'index']);
    
    // Rotas de notificações (com rate limiting)
    Route::middleware('throttle:60,1')->group(function () {
        Route::post('notifications/register-token', [NotificationController::class, 'registerToken']);
        Route::get('notifications', [NotificationController::class, 'getNotifications']);
        Route::post('notifications/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::post('notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);
        Route::get('notifications/stats', [NotificationController::class, 'getStats']);
        Route::post('notifications/deactivate-token', [NotificationController::class, 'deactivateToken']);
    });
    
    // Rotas de preferências de notificação (com rate limiting mais permissivo)
    Route::middleware('throttle:30,1')->group(function () {
        // Aceita GET e POST para compatibilidade com o front (POST carrega token/secret no body)
        Route::get('notification-preferences', [App\Http\Controllers\Api\NotificationPreferenceController::class, 'getPreferences']);
        Route::post('notification-preferences', [App\Http\Controllers\Api\NotificationPreferenceController::class, 'getPreferences']);
        Route::put('notification-preferences', [App\Http\Controllers\Api\NotificationPreferenceController::class, 'updatePreferences']);
        Route::post('notification-preferences/toggle/{type}', [App\Http\Controllers\Api\NotificationPreferenceController::class, 'togglePreference']);
        Route::post('notification-preferences/disable-all', [App\Http\Controllers\Api\NotificationPreferenceController::class, 'disableAll']);
        Route::post('notification-preferences/enable-all', [App\Http\Controllers\Api\NotificationPreferenceController::class, 'enableAll']);
    });
    
    // Rotas do 2FA
    Route::get('2fa/status', [App\Http\Controllers\TwoFactorAuthController::class, 'status']);
    Route::post('2fa/generate-qr', [App\Http\Controllers\TwoFactorAuthController::class, 'generateQrCode']);
    Route::post('2fa/verify', [App\Http\Controllers\TwoFactorAuthController::class, 'verifyCode']);
    Route::post('2fa/enable', [App\Http\Controllers\TwoFactorAuthController::class, 'enable']);
    Route::post('2fa/disable', [App\Http\Controllers\TwoFactorAuthController::class, 'disable']);
    
    // Rotas de segurança e conta
    // Rate limiting: 3 tentativas por hora (implementado no controller com Redis)
    Route::post('auth/change-password', [UserController::class, 'changePassword']);
    
    // Integração de API - Credenciais e IPs autorizados
    // Rate limiting: GET credentials (60 req/min), POST regenerate-secret (5 req/min), IP management (20 req/min)
    // CORS seguro: middleware 'secure.cors' controla origens permitidas via FRONTEND_URL
    Route::get('integration/credentials', [App\Http\Controllers\Api\IntegrationController::class, 'getCredentials'])->middleware(['secure.cors', 'throttle:60,1']);
    Route::post('integration/regenerate-secret', [App\Http\Controllers\Api\IntegrationController::class, 'regenerateSecret'])->middleware(['secure.cors', 'throttle:5,1']);
    Route::get('integration/allowed-ips', [App\Http\Controllers\Api\IntegrationController::class, 'getAllowedIPs'])->middleware(['secure.cors', 'throttle:60,1']);
    Route::post('integration/allowed-ips', [App\Http\Controllers\Api\IntegrationController::class, 'addAllowedIP'])->middleware(['secure.cors', 'throttle:20,1']);
    Route::delete('integration/allowed-ips/{ip}', [App\Http\Controllers\Api\IntegrationController::class, 'removeAllowedIP'])->middleware(['secure.cors', 'throttle:20,1']);
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

/* PAGARM CALLBACKS */
Route::post('pagarm/callback/deposit', [\App\Http\Controllers\Api\Adquirentes\PagArmController::class, 'callbackDeposit'])->middleware(['validate.webhook', 'throttle:30,1']);
Route::post('pagarm/callback/withdraw', [\App\Http\Controllers\Api\Adquirentes\PagArmController::class, 'callbackWithdraw'])->middleware(['validate.webhook', 'throttle:30,1']);