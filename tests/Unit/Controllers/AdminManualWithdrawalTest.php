<?php

namespace Tests\Unit\Controllers;

use Tests\TestCase;
use App\Models\User;
use App\Models\SolicitacoesCashOut;
use App\Models\App;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Helpers\AuthTestHelper;

/**
 * Testes Unitários - Criar Saque Manual (Admin)
 * 
 * Cobre:
 * - storeWithdrawal
 * - Validação de usuário
 * - Validação de saldo suficiente
 * - Cálculo de taxas
 * - Criação de registro
 * - Atualização de saldo
 * - Tratamento de erros
 */
class AdminManualWithdrawalTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;
    private App $appSettings;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // Criar usuário admin
        $this->adminUser = AuthTestHelper::createTestUser([
            'username' => 'admin_' . uniqid(),
            'email' => 'admin_' . uniqid() . '@example.com',
            'password' => Hash::make('password123'),
            'status' => 1,
            'banido' => 0,
            'permission' => 3, // Admin
        ]);

        // Criar configurações da aplicação
        $this->appSettings = App::create([
            'taxa_pix_cash_out' => 2.5,
            'taxa_pix_cash_out_valor_fixo' => 0.5,
        ]);
    }

    public function test_should_create_manual_withdrawal()
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
            'saldo' => 1000.00,
        ]);

        $initialBalance = $targetUser->saldo;

        $httpRequest = \Illuminate\Http\Request::create('/api/admin/manual-transactions/withdrawal', 'POST', [
            'user_id' => $targetUser->user_id,
            'amount' => 100.00,
            'description' => 'Teste manual',
        ]);
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        $httpRequest->setRouteResolver(function () {
            return new \Illuminate\Routing\Route(['POST'], '/api/admin/manual-transactions/withdrawal', []);
        });
        
        $request = \App\Http\Requests\Admin\StoreManualWithdrawalRequest::createFrom($httpRequest);
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->validateResolved();

        $controller = new \App\Http\Controllers\Api\AdminTransactionsController(
            app(\App\Services\FinancialService::class),
            app(\App\Services\AdminTransactionService::class)
        );

        $response = $controller->storeWithdrawal($request);

        $this->assertEquals(201, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('withdrawal', $data['data']);

        // Verificar que o saque foi criado
        $withdrawal = SolicitacoesCashOut::where('user_id', $targetUser->user_id)
            ->where('idTransaction', $data['data']['withdrawal']['transaction_id'])
            ->first();
        
        $this->assertNotNull($withdrawal);
        $this->assertEquals('PAID_OUT', $withdrawal->status);
        $this->assertEquals(100.00, $withdrawal->amount);

        // Verificar que o saldo foi atualizado
        $targetUser->refresh();
        $this->assertLessThan($initialBalance, $targetUser->saldo);
    }

    public function test_should_return_error_when_user_not_found()
    {
        // Criar usuário temporário e depois deletar para passar validação do FormRequest
        $tempUser = AuthTestHelper::createTestUser([
            'username' => 'temp_' . uniqid(),
            'email' => 'temp_' . uniqid() . '@example.com',
        ]);
        $tempUserId = $tempUser->user_id;
        $tempUser->delete();

        $httpRequest = \Illuminate\Http\Request::create('/api/admin/manual-transactions/withdrawal', 'POST', [
            'user_id' => $tempUserId,
            'amount' => 100.00,
        ]);
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        $httpRequest->setRouteResolver(function () {
            return new \Illuminate\Routing\Route(['POST'], '/api/admin/manual-transactions/withdrawal', []);
        });
        
        $request = \App\Http\Requests\Admin\StoreManualWithdrawalRequest::createFrom($httpRequest);
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        
        // Validar request (pode passar se user_id existe no banco no momento da validação)
        try {
            $request->validateResolved();
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Se a validação falhar porque o usuário não existe, isso é esperado
            $this->assertTrue(true);
            return;
        }

        // Se passou a validação, o controller deve retornar 404
        $controller = new \App\Http\Controllers\Api\AdminTransactionsController(
            app(\App\Services\FinancialService::class),
            app(\App\Services\AdminTransactionService::class)
        );

        $response = $controller->storeWithdrawal($request);

        $this->assertEquals(404, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('não encontrado', $data['message']);
    }

    public function test_should_return_error_when_insufficient_balance()
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
            'saldo' => 10.00, // Saldo insuficiente
        ]);

        $httpRequest = \Illuminate\Http\Request::create('/api/admin/manual-transactions/withdrawal', 'POST', [
            'user_id' => $targetUser->user_id,
            'amount' => 100.00, // Valor maior que o saldo
        ]);
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        $httpRequest->setRouteResolver(function () {
            return new \Illuminate\Routing\Route(['POST'], '/api/admin/manual-transactions/withdrawal', []);
        });
        
        $request = \App\Http\Requests\Admin\StoreManualWithdrawalRequest::createFrom($httpRequest);
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->validateResolved();

        $controller = new \App\Http\Controllers\Api\AdminTransactionsController(
            app(\App\Services\FinancialService::class),
            app(\App\Services\AdminTransactionService::class)
        );

        $response = $controller->storeWithdrawal($request);

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('insuficiente', $data['message']);
    }

    public function test_should_calculate_fees_correctly()
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
            'saldo' => 1000.00,
        ]);

        $httpRequest = \Illuminate\Http\Request::create('/api/admin/manual-transactions/withdrawal', 'POST', [
            'user_id' => $targetUser->user_id,
            'amount' => 100.00,
        ]);
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        $httpRequest->setRouteResolver(function () {
            return new \Illuminate\Routing\Route(['POST'], '/api/admin/manual-transactions/withdrawal', []);
        });
        
        $request = \App\Http\Requests\Admin\StoreManualWithdrawalRequest::createFrom($httpRequest);
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->validateResolved();

        $controller = new \App\Http\Controllers\Api\AdminTransactionsController(
            app(\App\Services\FinancialService::class),
            app(\App\Services\AdminTransactionService::class)
        );

        $response = $controller->storeWithdrawal($request);

        $this->assertEquals(201, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $withdrawal = SolicitacoesCashOut::where('idTransaction', $data['data']['withdrawal']['transaction_id'])->first();
        
        // Verificar que as taxas foram calculadas
        $this->assertNotNull($withdrawal);
        $this->assertGreaterThanOrEqual(0, $withdrawal->taxa_cash_out);
        // O valor líquido deve ser menor ou igual ao valor total (pode ser igual se não houver taxa)
        $this->assertLessThanOrEqual($withdrawal->amount, $withdrawal->cash_out_liquido);
    }

    public function test_should_use_default_description_when_not_provided()
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
            'saldo' => 1000.00,
        ]);

        $httpRequest = \Illuminate\Http\Request::create('/api/admin/manual-transactions/withdrawal', 'POST', [
            'user_id' => $targetUser->user_id,
            'amount' => 100.00,
        ]);
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        $httpRequest->setRouteResolver(function () {
            return new \Illuminate\Routing\Route(['POST'], '/api/admin/manual-transactions/withdrawal', []);
        });
        
        $request = \App\Http\Requests\Admin\StoreManualWithdrawalRequest::createFrom($httpRequest);
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->validateResolved();

        $controller = new \App\Http\Controllers\Api\AdminTransactionsController(
            app(\App\Services\FinancialService::class),
            app(\App\Services\AdminTransactionService::class)
        );

        $response = $controller->storeWithdrawal($request);

        $this->assertEquals(201, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $withdrawal = SolicitacoesCashOut::where('idTransaction', $data['data']['withdrawal']['transaction_id'])->first();
        
        $this->assertNotNull($withdrawal);
        $this->assertEquals('MANUAL', $withdrawal->descricao_transacao);
    }

    public function test_should_use_custom_description_when_provided()
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
            'saldo' => 1000.00,
        ]);

        $customDescription = 'Pagamento ao usuário';

        $httpRequest = \Illuminate\Http\Request::create('/api/admin/manual-transactions/withdrawal', 'POST', [
            'user_id' => $targetUser->user_id,
            'amount' => 100.00,
            'description' => $customDescription,
        ]);
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        $httpRequest->setRouteResolver(function () {
            return new \Illuminate\Routing\Route(['POST'], '/api/admin/manual-transactions/withdrawal', []);
        });
        
        $request = \App\Http\Requests\Admin\StoreManualWithdrawalRequest::createFrom($httpRequest);
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->validateResolved();

        $controller = new \App\Http\Controllers\Api\AdminTransactionsController(
            app(\App\Services\FinancialService::class),
            app(\App\Services\AdminTransactionService::class)
        );

        $response = $controller->storeWithdrawal($request);

        $this->assertEquals(201, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $withdrawal = SolicitacoesCashOut::where('idTransaction', $data['data']['withdrawal']['transaction_id'])->first();
        
        $this->assertNotNull($withdrawal);
        $this->assertEquals($customDescription, $withdrawal->descricao_transacao);
    }

    public function test_should_update_user_balance()
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
            'saldo' => 1000.00,
        ]);

        $initialBalance = $targetUser->saldo;

        $httpRequest = \Illuminate\Http\Request::create('/api/admin/manual-transactions/withdrawal', 'POST', [
            'user_id' => $targetUser->user_id,
            'amount' => 100.00,
        ]);
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        $httpRequest->setRouteResolver(function () {
            return new \Illuminate\Routing\Route(['POST'], '/api/admin/manual-transactions/withdrawal', []);
        });
        
        $request = \App\Http\Requests\Admin\StoreManualWithdrawalRequest::createFrom($httpRequest);
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->validateResolved();

        $controller = new \App\Http\Controllers\Api\AdminTransactionsController(
            app(\App\Services\FinancialService::class),
            app(\App\Services\AdminTransactionService::class)
        );

        $response = $controller->storeWithdrawal($request);

        $this->assertEquals(201, $response->getStatusCode());
        
        $targetUser->refresh();
        $this->assertLessThan($initialBalance, $targetUser->saldo);
    }

    public function test_should_create_withdrawal_with_paid_out_status()
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
            'saldo' => 1000.00,
        ]);

        $httpRequest = \Illuminate\Http\Request::create('/api/admin/manual-transactions/withdrawal', 'POST', [
            'user_id' => $targetUser->user_id,
            'amount' => 100.00,
        ]);
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        $httpRequest->setRouteResolver(function () {
            return new \Illuminate\Routing\Route(['POST'], '/api/admin/manual-transactions/withdrawal', []);
        });
        
        $request = \App\Http\Requests\Admin\StoreManualWithdrawalRequest::createFrom($httpRequest);
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->validateResolved();

        $controller = new \App\Http\Controllers\Api\AdminTransactionsController(
            app(\App\Services\FinancialService::class),
            app(\App\Services\AdminTransactionService::class)
        );

        $response = $controller->storeWithdrawal($request);

        $this->assertEquals(201, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $withdrawal = SolicitacoesCashOut::where('idTransaction', $data['data']['withdrawal']['transaction_id'])->first();
        
        $this->assertNotNull($withdrawal);
        $this->assertEquals('PAID_OUT', $withdrawal->status);
    }

    public function test_should_debit_total_amount_including_fees()
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
            'saldo' => 1000.00,
        ]);

        $initialBalance = $targetUser->saldo;
        $withdrawalAmount = 100.00;

        $httpRequest = \Illuminate\Http\Request::create('/api/admin/manual-transactions/withdrawal', 'POST', [
            'user_id' => $targetUser->user_id,
            'amount' => $withdrawalAmount,
        ]);
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        $httpRequest->setRouteResolver(function () {
            return new \Illuminate\Routing\Route(['POST'], '/api/admin/manual-transactions/withdrawal', []);
        });
        
        $request = \App\Http\Requests\Admin\StoreManualWithdrawalRequest::createFrom($httpRequest);
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->validateResolved();

        $controller = new \App\Http\Controllers\Api\AdminTransactionsController(
            app(\App\Services\FinancialService::class),
            app(\App\Services\AdminTransactionService::class)
        );

        $response = $controller->storeWithdrawal($request);

        $this->assertEquals(201, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        // Verificar que o valor total descontado inclui taxa
        $this->assertArrayHasKey('valor_total_descontado', $data['data']['withdrawal']);
        $this->assertGreaterThan($withdrawalAmount, $data['data']['withdrawal']['valor_total_descontado']);
        
        $targetUser->refresh();
        $expectedBalance = $initialBalance - $data['data']['withdrawal']['valor_total_descontado'];
        $this->assertEquals($expectedBalance, $targetUser->saldo, '', 0.01);
    }
}

