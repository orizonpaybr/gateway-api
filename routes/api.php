<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\BilletController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\SaqueController;
use App\Http\Controllers\Api\DepositController;
use Illuminate\Support\Facades\Artisan;
use App\Http\Controllers\Api\PixInfracoesController;
use App\Http\Controllers\Api\PixKeyController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| IMPORTANTE: CORS é gerenciado pelo middleware SecureCors globalmente.
| NÃO adicionar headers Access-Control-Allow-Origin manualmente nas rotas.
| Configurar FRONTEND_URL no .env para permitir origens específicas.
|
*/

/* AUTHENTICATION ROUTES (públicas) */
Route::middleware(['check.ip.reputation', 'throttle.login.failures'])->group(function () {
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/register', [AuthController::class, 'register']);
    Route::post('auth/verify-2fa', [AuthController::class, 'verify2FA']);
    Route::post('auth/validate-registration', [AuthController::class, 'validateRegistrationData']);
    Route::post('auth/logout', [AuthController::class, 'logout']);
});

// Setup 2FA no login — fora do verify.jwt para aceitar token temporário 2fa_setup
Route::middleware(['verify.jwt.or.2fa.setup', 'throttle.two.factor:10,60'])->group(function () {
    Route::post('2fa/generate-qr', [App\Http\Controllers\TwoFactorAuthController::class, 'generateQrCode']);
    Route::post('2fa/enable', [App\Http\Controllers\TwoFactorAuthController::class, 'enable']);
});

// Rotas protegidas com JWT (para frontend)
// throttle:120,1 protege contra abuso/flood nas rotas de dashboard, transações e saldo
// (limite por usuário/IP; folgado o bastante para dashboards com polling de vários widgets).
Route::middleware(['verify.jwt', 'throttle:120,1'])->group(function () {
    Route::get('auth/verify', [AuthController::class, 'verifyToken']);
    Route::get('balance', [UserController::class, 'getBalance']);
    Route::get('transactions', [UserController::class, 'getTransactions']);
    Route::get('transactions/{id}', [UserController::class, 'getTransactionById']);
    Route::get('user/profile', [UserController::class, 'getProfile']);
    Route::post('pix/generate-qr', [UserController::class, 'generatePixQR'])->middleware(['throttle.fyhub.pix', 'throttle.treeal.pix', 'throttle.fluxpayments.pix', 'throttle.paya55.pix']);
    Route::get('extrato', [UserController::class, 'getExtrato']);
    Route::get('user/real-data', [UserController::class, 'getRealData']);
    Route::get('dashboard/stats', [UserController::class, 'getDashboardStats']);
    Route::get('dashboard/interactive-movement', [UserController::class, 'getInteractiveMovement']);
    Route::get('dashboard/transaction-summary', [UserController::class, 'getTransactionSummary']);
    Route::get('gamification/journey', [UserController::class, 'getGamificationData']);
    Route::get('gamification/sidebar', [UserController::class, 'getSidebarGamificationData']);

    // Infrações Pix (MED — Mecanismo Especial de Devolução)
    Route::get('pix/infracoes', [PixInfracoesController::class, 'index']);
    Route::get('pix/infracoes/{id}', [PixInfracoesController::class, 'show'])->where('id', '[0-9]+');
    Route::post('pix/infracoes/{id}/defense', [PixInfracoesController::class, 'defense'])->where('id', '[0-9]+');

    // Chaves PIX
    Route::get('pix/keys', [PixKeyController::class, 'index']);
    Route::post('pix/keys', [PixKeyController::class, 'store']);
    Route::get('pix/keys/{id}', [PixKeyController::class, 'show']);
    Route::put('pix/keys/{id}', [PixKeyController::class, 'update']);
    Route::delete('pix/keys/{id}', [PixKeyController::class, 'destroy']);
    Route::post('pix/keys/{id}/set-default', [PixKeyController::class, 'setDefault']);
    Route::post('pix/withdraw-with-key', [PixKeyController::class, 'withdraw'])->middleware(['throttle.fyhub.pix', 'throttle.treeal.pix', 'throttle.fluxpayments.pix', 'throttle.paya55.pix']);
    
    // QR Codes (Otimizado)
    Route::get('qrcodes', [App\Http\Controllers\Api\QRCodeController::class, 'index']);
    
    // Dashboard Administrativo (apenas para admins - permission == 3)
    Route::middleware(['ensure.admin'])->group(function () {
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
        Route::get('admin/acquirers/{id}', [App\Http\Controllers\Api\AdminDashboardController::class, 'showAcquirer'])->where('id', '[0-9]+');
        Route::post('admin/acquirers', [App\Http\Controllers\Api\AdminDashboardController::class, 'storeAcquirer']);
        Route::put('admin/acquirers/{id}', [App\Http\Controllers\Api\AdminDashboardController::class, 'updateAcquirer'])->where('id', '[0-9]+');
        Route::post('admin/acquirers/{id}/set-default', [App\Http\Controllers\Api\AdminDashboardController::class, 'setDefaultAcquirer'])->where('id', '[0-9]+');
        
        // Rotas de configuração de saque (Apenas Admin)
        Route::put('admin/withdrawals/config', [App\Http\Controllers\Api\WithdrawalController::class, 'updateConfig']);
        
        // Rotas do módulo financeiro (Admin)
        Route::get('admin/financial/transactions', [App\Http\Controllers\Api\FinancialController::class, 'getAllTransactions']);
        Route::get('admin/financial/transactions/stats', [App\Http\Controllers\Api\FinancialController::class, 'getTransactionsStats']);
        Route::get('admin/financial/wallets', [App\Http\Controllers\Api\FinancialController::class, 'getWallets']);
        Route::get('admin/financial/wallets/stats', [App\Http\Controllers\Api\FinancialController::class, 'getWalletsStats']);
        Route::get('admin/financial/deposits', [App\Http\Controllers\Api\FinancialController::class, 'getDeposits']);
        Route::get('admin/financial/deposits/stats', [App\Http\Controllers\Api\FinancialController::class, 'getDepositsStats']);
        Route::put('admin/financial/deposits/{id}/status', [App\Http\Controllers\Api\FinancialController::class, 'updateDepositStatus']);
        Route::post('admin/financial/deposits/{id}/refund', [App\Http\Controllers\Api\FinancialController::class, 'refundDeposit'])->where('id', '[0-9]+');
        Route::get('admin/financial/withdrawals', [App\Http\Controllers\Api\FinancialController::class, 'getWithdrawals']);
        Route::get('admin/financial/withdrawals/stats', [App\Http\Controllers\Api\FinancialController::class, 'getWithdrawalsStats']);
        
        // Relatório de conciliação diária por usuário (Admin)
        Route::get('admin/reports/reconciliation', [App\Http\Controllers\Api\AdminReconciliationController::class, 'getReport']);
        Route::get('admin/reports/reconciliation/export', [App\Http\Controllers\Api\AdminReconciliationController::class, 'exportReport']);
        
        // Rotas de configurações do gateway (Admin)
        Route::get('admin/settings', [App\Http\Controllers\Api\GatewaySettingsController::class, 'getSettings']);
        Route::put('admin/settings', [App\Http\Controllers\Api\GatewaySettingsController::class, 'updateSettings']);
        
        // Rotas de gerenciamento de níveis de gamificação (Admin)
        Route::get('admin/levels', [App\Http\Controllers\Api\AdminLevelsController::class, 'index']);
        Route::get('admin/levels/{id}', [App\Http\Controllers\Api\AdminLevelsController::class, 'show'])->where('id', '[0-9]+');
        Route::put('admin/levels/{id}', [App\Http\Controllers\Api\AdminLevelsController::class, 'update'])->where('id', '[0-9]+');
        Route::post('admin/levels/toggle-active', [App\Http\Controllers\Api\AdminLevelsController::class, 'toggleActive']);

        // Auditoria de autenticação e IPs bloqueados
        Route::get('admin/auth-events', [App\Http\Controllers\Api\AdminAuthEventsController::class, 'index']);
        Route::get('admin/blocked-ips', [App\Http\Controllers\Api\AdminAuthEventsController::class, 'listBlockedIps']);
        Route::post('admin/blocked-ips', [App\Http\Controllers\Api\AdminAuthEventsController::class, 'storeBlockedIp']);
        Route::delete('admin/blocked-ips/{id}', [App\Http\Controllers\Api\AdminAuthEventsController::class, 'destroyBlockedIp'])->where('id', '[0-9]+');
    });
    
    // Rotas compartilhadas entre Admin (3) e Gerente (2)
    Route::middleware(['ensure.admin_or_manager'])->group(function () {
        // Lista de usuários
        Route::get('admin/dashboard/users', [App\Http\Controllers\Api\AdminDashboardController::class, 'getUsers']);
        
        // Estatísticas de usuários (cards: total, mês, pendentes, banidos)
        Route::get('admin/dashboard/users-stats', [App\Http\Controllers\Api\AdminDashboardController::class, 'getUserStats']);
        
        // Documentos do usuário (autenticado; não expõe RG/CPF em URL pública)
        Route::get('admin/users/{id}/documents/{type}', [App\Http\Controllers\Api\AdminDashboardController::class, 'getUserDocument'])->where([
            'id' => '[0-9]+',
            'type' => 'rg_frente|rg_verso|selfie_rg',
        ]);
        
        Route::get('admin/users/{id}', [App\Http\Controllers\Api\AdminDashboardController::class, 'showUser']);
        
        // Editar usuário
        Route::put('admin/users/{id}', [App\Http\Controllers\Api\AdminDashboardController::class, 'updateUser']);
        
        // Bloquear/desbloquear usuário
        Route::post('admin/users/{id}/toggle-block', [App\Http\Controllers\Api\AdminDashboardController::class, 'toggleBlockUser']);
        Route::post('admin/users/{id}/unlock-login', [App\Http\Controllers\Api\AdminDashboardController::class, 'unlockLogin']);
        
        // Bloquear/desbloquear saque do usuário
        Route::post('admin/users/{id}/toggle-withdraw-block', [App\Http\Controllers\Api\AdminDashboardController::class, 'toggleWithdrawBlock']);
        
        // Ver taxas padrão
        Route::get('admin/default-fees', [App\Http\Controllers\Api\AdminDashboardController::class, 'getDefaultFees']);
        
        // Rotas de gerenciamento de saques (Admin e Gerente)
        Route::get('admin/withdrawals', [App\Http\Controllers\Api\WithdrawalController::class, 'index']);
        Route::get('admin/withdrawals/stats', [App\Http\Controllers\Api\WithdrawalController::class, 'stats']);
        Route::get('admin/withdrawals/config', [App\Http\Controllers\Api\WithdrawalController::class, 'getConfig']);
        Route::get('admin/withdrawals/{id}', [App\Http\Controllers\Api\WithdrawalController::class, 'show'])->where('id', '[0-9]+');
        Route::post('admin/withdrawals/{id}/approve', [App\Http\Controllers\Api\WithdrawalController::class, 'approve'])->where('id', '[0-9]+');
        Route::post('admin/withdrawals/{id}/reject', [App\Http\Controllers\Api\WithdrawalController::class, 'reject'])->where('id', '[0-9]+');
    });
    
    Route::middleware(['throttle.two.factor:10,60'])->group(function () {
        Route::get('2fa/status', [App\Http\Controllers\TwoFactorAuthController::class, 'status']);
        Route::post('2fa/verify', [App\Http\Controllers\TwoFactorAuthController::class, 'verifyCode']);
    });
    Route::middleware(['throttle.two.factor:5,60'])->group(function () {
        Route::post('2fa/disable', [App\Http\Controllers\TwoFactorAuthController::class, 'disable']);
    });
    
    // Rotas de segurança e conta
    Route::post('auth/change-password', [UserController::class, 'changePassword']);
    
    // Rotas de afiliados (link + totais: indicados, total ganho, ganho do mês)
    Route::get('user/affiliate-link', [UserController::class, 'generateAffiliateLink']);
    
    // Integração de API - Credenciais e IPs autorizados
    Route::middleware(['throttle:60,1'])->group(function () {
        Route::get('integration/credentials', [App\Http\Controllers\Api\IntegrationController::class, 'getCredentials']);
        Route::get('integration/allowed-ips', [App\Http\Controllers\Api\IntegrationController::class, 'getAllowedIPs']);
    });
    Route::middleware(['throttle:5,1'])->post('integration/regenerate-secret', [App\Http\Controllers\Api\IntegrationController::class, 'regenerateSecret']);
    Route::middleware(['throttle:20,1'])->group(function () {
        Route::post('integration/allowed-ips', [App\Http\Controllers\Api\IntegrationController::class, 'addAllowedIP']);
        // IP no body (CIDR contém "/" — não pode ir na URL por limitação do nginx)
        Route::delete('integration/allowed-ips', [App\Http\Controllers\Api\IntegrationController::class, 'removeAllowedIP']);
        // Legado: apenas IPv4 sem barra no path
        Route::delete('integration/allowed-ips/{ip}', [App\Http\Controllers\Api\IntegrationController::class, 'removeAllowedIP'])
            ->where('ip', '[0-9.]+');
    });
});

// Rotas protegidas com token + secret (para integrações externas e APIs)
Route::middleware(['check.token.secret'])->group(function () {
    // Essas rotas ainda podem usar token + secret para compatibilidade com integrações externas
});

// Rota de utilitário (apenas ambiente local, autenticado)
Route::get('/link-storage', function (Request $request) {
    // Verificar se está em ambiente local
    if (!app()->environment('local')) {
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

/* SALDO (consulta para integradores — somente leitura) */
Route::middleware(['throttle.balance.failures', 'check.token.secret', 'throttle:balance-check'])->get('wallet/balance', [\App\Http\Controllers\Api\Client\BalanceController::class, 'show']);

/* PIX */
Route::middleware(['check.token.secret', 'throttle.fyhub.pix', 'throttle.treeal.pix', 'throttle.fluxpayments.pix', 'throttle.paya55.pix', 'throttle:pix-in'])->post('wallet/deposit/payment', [DepositController::class, 'makeDeposit']);
Route::middleware(['check.token.secret', 'check.allowed.ip', 'throttle.fyhub.pix', 'throttle.treeal.pix', 'throttle.fluxpayments.pix', 'throttle.paya55.pix', 'throttle:pix-out'])->post('pixout', [SaqueController::class, 'makePayment']);
Route::middleware('throttle:status-check')->post('status', [DepositController::class, 'statusDeposito']);

/* CARTÃO */
Route::middleware(['check.token.secret', 'throttle:60,1'])->post('card/payment', [\App\Http\Controllers\Api\CardPaymentController::class, 'createPayment']);
Route::middleware(['check.token.secret', 'throttle:60,1'])->get('card/payment/{transactionId}', [\App\Http\Controllers\Api\CardPaymentController::class, 'getPaymentStatus']);
Route::post('card/webhook', [\App\Http\Controllers\Api\CardPaymentController::class, 'webhook']);

/* CARTÃO PAGAR.ME - Depósitos via cartão de crédito */
Route::middleware(['check.token.secret', 'throttle:30,1'])->post('deposit/card', [DepositController::class, 'makeCardDeposit']);
Route::middleware(['verify.jwt', 'throttle:60,1'])->group(function () {
    Route::get('cards', [DepositController::class, 'listSavedCards']);
    Route::delete('cards/{cardId}', [DepositController::class, 'deleteSavedCard']);
    Route::post('cards/{cardId}/default', [DepositController::class, 'setDefaultCard']);
});

/* BOLETO */
Route::middleware(['check.token.secret', 'throttle:5,1'])->post('billet/charge', [BilletController::class, 'charge']);
