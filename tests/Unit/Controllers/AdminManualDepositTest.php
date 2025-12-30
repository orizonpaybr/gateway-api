<?php

namespace Tests\Unit\Controllers;

use Tests\TestCase;
use App\Models\User;
use App\Models\Solicitacoes;
use App\Models\App;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Helpers\AuthTestHelper;

/**
 * Testes Unitários - Criar Depósito Manual (Admin)
 * 
 * Cobre:
 * - storeDeposit
 * - Validação de usuário
 * - Cálculo de taxas
 * - Criação de registro
 * - Atualização de saldo
 * - Tratamento de erros
 */
class AdminManualDepositTest extends TestCase
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
            'taxa_pix_cash_in' => 2.5,
            'taxa_pix_cash_in_valor_fixo' => 0.5,
            'taxa_pix_cash_in_adquirente' => 1.0,
        ]);
    }

    public function test_should_create_manual_deposit()
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
            'saldo' => 0,
        ]);

        $initialBalance = $targetUser->saldo;

        $httpRequest = \Illuminate\Http\Request::create('/api/admin/manual-transactions/deposits', 'POST', [
            'user_id' => $targetUser->user_id,
            'amount' => 100.00,
            'description' => 'Teste manual',
        ]);
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        $httpRequest->setRouteResolver(function () {
            return new \Illuminate\Routing\Route(['POST'], '/api/admin/manual-transactions/deposits', []);
        });
        
        $request = \App\Http\Requests\Admin\StoreManualDepositRequest::createFrom($httpRequest);
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->validateResolved();

        $controller = new \App\Http\Controllers\Api\AdminTransactionsController(
            app(\App\Services\FinancialService::class),
            app(\App\Services\AdminTransactionService::class)
        );

        $response = $controller->storeDeposit($request);

        $this->assertEquals(201, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('deposit', $data['data']);

        // Verificar que o depósito foi criado
        $deposit = Solicitacoes::where('user_id', $targetUser->user_id)
            ->where('idTransaction', $data['data']['deposit']['transaction_id'])
            ->first();
        
        $this->assertNotNull($deposit);
        $this->assertEquals('PAID_OUT', $deposit->status);
        $this->assertEquals(100.00, $deposit->amount);

        // Verificar que o saldo foi atualizado
        $targetUser->refresh();
        $this->assertGreaterThan($initialBalance, $targetUser->saldo);
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

        $httpRequest = \Illuminate\Http\Request::create('/api/admin/manual-transactions/deposits', 'POST', [
            'user_id' => $tempUserId,
            'amount' => 100.00,
        ]);
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        $httpRequest->setRouteResolver(function () {
            return new \Illuminate\Routing\Route(['POST'], '/api/admin/manual-transactions/deposits', []);
        });
        
        $request = \App\Http\Requests\Admin\StoreManualDepositRequest::createFrom($httpRequest);
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

        $response = $controller->storeDeposit($request);

        $this->assertEquals(404, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('não encontrado', $data['message']);
    }

    public function test_should_calculate_fees_correctly()
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
        ]);

        $httpRequest = \Illuminate\Http\Request::create('/api/admin/manual-transactions/deposits', 'POST', [
            'user_id' => $targetUser->user_id,
            'amount' => 100.00,
        ]);
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        $httpRequest->setRouteResolver(function () {
            return new \Illuminate\Routing\Route(['POST'], '/api/admin/manual-transactions/deposits', []);
        });
        
        $request = \App\Http\Requests\Admin\StoreManualDepositRequest::createFrom($httpRequest);
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->validateResolved();

        $controller = new \App\Http\Controllers\Api\AdminTransactionsController(
            app(\App\Services\FinancialService::class),
            app(\App\Services\AdminTransactionService::class)
        );

        $response = $controller->storeDeposit($request);

        $this->assertEquals(201, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $deposit = Solicitacoes::where('idTransaction', $data['data']['deposit']['transaction_id'])->first();
        
        // Verificar que as taxas foram calculadas
        $this->assertNotNull($deposit);
        $this->assertLessThan($deposit->amount, $deposit->deposito_liquido);
        $this->assertGreaterThan(0, $deposit->taxa_cash_in);
    }

    public function test_should_use_default_description_when_not_provided()
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
        ]);

        $httpRequest = \Illuminate\Http\Request::create('/api/admin/manual-transactions/deposits', 'POST', [
            'user_id' => $targetUser->user_id,
            'amount' => 100.00,
        ]);
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        $httpRequest->setRouteResolver(function () {
            return new \Illuminate\Routing\Route(['POST'], '/api/admin/manual-transactions/deposits', []);
        });
        
        $request = \App\Http\Requests\Admin\StoreManualDepositRequest::createFrom($httpRequest);
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->validateResolved();

        $controller = new \App\Http\Controllers\Api\AdminTransactionsController(
            app(\App\Services\FinancialService::class),
            app(\App\Services\AdminTransactionService::class)
        );

        $response = $controller->storeDeposit($request);

        $this->assertEquals(201, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $deposit = Solicitacoes::where('idTransaction', $data['data']['deposit']['transaction_id'])->first();
        
        $this->assertNotNull($deposit);
        $this->assertEquals('MANUAL', $deposit->descricao_transacao);
    }

    public function test_should_use_custom_description_when_provided()
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
        ]);

        $customDescription = 'Bônus de performance';

        $httpRequest = \Illuminate\Http\Request::create('/api/admin/manual-transactions/deposits', 'POST', [
            'user_id' => $targetUser->user_id,
            'amount' => 100.00,
            'description' => $customDescription,
        ]);
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        $httpRequest->setRouteResolver(function () {
            return new \Illuminate\Routing\Route(['POST'], '/api/admin/manual-transactions/deposits', []);
        });
        
        $request = \App\Http\Requests\Admin\StoreManualDepositRequest::createFrom($httpRequest);
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->validateResolved();

        $controller = new \App\Http\Controllers\Api\AdminTransactionsController(
            app(\App\Services\FinancialService::class),
            app(\App\Services\AdminTransactionService::class)
        );

        $response = $controller->storeDeposit($request);

        $this->assertEquals(201, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $deposit = Solicitacoes::where('idTransaction', $data['data']['deposit']['transaction_id'])->first();
        
        $this->assertNotNull($deposit);
        $this->assertEquals($customDescription, $deposit->descricao_transacao);
    }

    public function test_should_update_user_balance()
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
            'saldo' => 50.00,
        ]);

        $initialBalance = $targetUser->saldo;

        $httpRequest = \Illuminate\Http\Request::create('/api/admin/manual-transactions/deposits', 'POST', [
            'user_id' => $targetUser->user_id,
            'amount' => 100.00,
        ]);
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        $httpRequest->setRouteResolver(function () {
            return new \Illuminate\Routing\Route(['POST'], '/api/admin/manual-transactions/deposits', []);
        });
        
        $request = \App\Http\Requests\Admin\StoreManualDepositRequest::createFrom($httpRequest);
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->validateResolved();

        $controller = new \App\Http\Controllers\Api\AdminTransactionsController(
            app(\App\Services\FinancialService::class),
            app(\App\Services\AdminTransactionService::class)
        );

        $response = $controller->storeDeposit($request);

        $this->assertEquals(201, $response->getStatusCode());
        
        $targetUser->refresh();
        $this->assertGreaterThan($initialBalance, $targetUser->saldo);
    }

    public function test_should_rollback_on_error()
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
        ]);

        $initialBalance = $targetUser->saldo;
        $initialDepositsCount = Solicitacoes::where('user_id', $targetUser->user_id)->count();

        // Simular erro forçando uma exceção (removendo App settings)
        App::truncate();

        $httpRequest = \Illuminate\Http\Request::create('/api/admin/manual-transactions/deposits', 'POST', [
            'user_id' => $targetUser->user_id,
            'amount' => 100.00,
        ]);
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        $httpRequest->setRouteResolver(function () {
            return new \Illuminate\Routing\Route(['POST'], '/api/admin/manual-transactions/deposits', []);
        });
        
        $request = \App\Http\Requests\Admin\StoreManualDepositRequest::createFrom($httpRequest);
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->validateResolved();

        $controller = new \App\Http\Controllers\Api\AdminTransactionsController(
            app(\App\Services\FinancialService::class),
            app(\App\Services\AdminTransactionService::class)
        );

        $response = $controller->storeDeposit($request);

        // Deve retornar erro
        $this->assertContains($response->getStatusCode(), [500, 404]);

        // Verificar que não foi criado depósito
        $finalDepositsCount = Solicitacoes::where('user_id', $targetUser->user_id)->count();
        $this->assertEquals($initialDepositsCount, $finalDepositsCount);

        // Verificar que saldo não foi alterado
        $targetUser->refresh();
        $this->assertEquals($initialBalance, $targetUser->saldo);
    }

    public function test_should_create_deposit_with_paid_out_status()
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
        ]);

        $httpRequest = \Illuminate\Http\Request::create('/api/admin/manual-transactions/deposits', 'POST', [
            'user_id' => $targetUser->user_id,
            'amount' => 100.00,
        ]);
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        $httpRequest->setRouteResolver(function () {
            return new \Illuminate\Routing\Route(['POST'], '/api/admin/manual-transactions/deposits', []);
        });
        
        $request = \App\Http\Requests\Admin\StoreManualDepositRequest::createFrom($httpRequest);
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->validateResolved();

        $controller = new \App\Http\Controllers\Api\AdminTransactionsController(
            app(\App\Services\FinancialService::class),
            app(\App\Services\AdminTransactionService::class)
        );

        $response = $controller->storeDeposit($request);

        $this->assertEquals(201, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $deposit = Solicitacoes::where('idTransaction', $data['data']['deposit']['transaction_id'])->first();
        
        $this->assertNotNull($deposit);
        $this->assertEquals('PAID_OUT', $deposit->status);
    }
}

