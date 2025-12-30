<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

/**
 * Testes Unitários - Serviço de Busca de Transações
 * Foco: Funcionalidade, Validação, Lógica de Negócio
 */
class TransactionSearchServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /**
     * Helper para criar Solicitacoes com todos os campos obrigatórios
     */
    private function createSolicitacao(array $attributes = []): Solicitacoes
    {
        $defaults = [
            'user_id' => 'testuser',
            'idTransaction' => 'TXN' . uniqid(),
            'externalreference' => 'EXT' . uniqid(),
            'amount' => 100.00,
            'deposito_liquido' => 97.50,
            'taxa_cash_in' => 2.50,
            'status' => 'PAID_OUT',
            'date' => now(),
            'client_name' => 'Cliente Test',
            'client_document' => '12345678900',
            'client_email' => 'cliente@test.com',
            'client_telefone' => '11999999999',
            'qrcode_pix' => 'https://example.com/qr',
            'paymentcode' => 'PAY' . uniqid(),
            'paymentCodeBase64' => base64_encode('PAY' . uniqid()),
            'adquirente_ref' => 'Banco Test',
            'taxa_pix_cash_in_adquirente' => 1.0,
            'taxa_pix_cash_in_valor_fixo' => 0.5,
            'executor_ordem' => 'EXEC' . uniqid(),
            'descricao_transacao' => 'Teste',
        ];

        $merged = array_merge($defaults, $attributes);
        
        // Processar user_id após merge - se for objeto User, extrair username
        if (isset($merged['user_id']) && is_object($merged['user_id'])) {
            if (isset($merged['user_id']->username)) {
                $merged['user_id'] = $merged['user_id']->username;
            } elseif (isset($merged['user_id']->user_id)) {
                $merged['user_id'] = $merged['user_id']->user_id;
            } else {
                $merged['user_id'] = 'testuser';
            }
        }

        return Solicitacoes::create($merged);
    }

    /**
     * Helper para criar SolicitacoesCashOut com todos os campos obrigatórios
     */
    private function createSolicitacaoCashOut(array $attributes = []): SolicitacoesCashOut
    {
        $defaults = [
            'user_id' => 'testuser',
            'idTransaction' => 'TXN' . uniqid(),
            'externalreference' => 'EXT' . uniqid(),
            'amount' => 50.00,
            'cash_out_liquido' => 49.00,
            'taxa_cash_out' => 1.00,
            'status' => 'PAID_OUT',
            'date' => now(),
            'beneficiaryname' => 'Beneficiário Test',
            'beneficiarydocument' => '98765432100',
            'executor_ordem' => 'EXEC' . uniqid(),
            'descricao_transacao' => 'Saque Teste',
            'pix' => 'MANUAL',
            'pixkey' => 'MANUAL',
            'type' => 'pix',
        ];

        $merged = array_merge($defaults, $attributes);
        
        // Processar user_id após merge - se for objeto User, extrair username
        if (isset($merged['user_id']) && is_object($merged['user_id'])) {
            if (isset($merged['user_id']->username)) {
                $merged['user_id'] = $merged['user_id']->username;
            } elseif (isset($merged['user_id']->user_id)) {
                $merged['user_id'] = $merged['user_id']->user_id;
            } else {
                $merged['user_id'] = 'testuser';
            }
        }

        return SolicitacoesCashOut::create($merged);
    }

    public function test_deve_buscar_transacao_por_id_de_deposito(): void
    {
        $user = User::factory()->create([
            'username' => 'testuser',
            'user_id' => 'testuser', // Garantir que user_id corresponde ao username
        ]);
        
        $this->createSolicitacao([
            'user_id' => $user,
            'idTransaction' => 'TXN123456',
            'externalreference' => 'EXT789',
            'amount' => 1000.00,
            'deposito_liquido' => 975.00,
            'taxa_cash_in' => 25.00,
            'client_name' => 'João Silva',
            'client_document' => '12345678900',
            'client_email' => 'joao@test.com',
            'descricao_transacao' => 'Pagamento Teste',
        ]);

        $controller = new \App\Http\Controllers\Api\UserController();
        $request = \Illuminate\Http\Request::create('/api/transactions', 'GET', [
            'busca' => 'TXN123456',
            'page' => 1,
            'limit' => 10,
        ]);
        $request->setUserResolver(fn() => $user);
        $request->merge(['user_auth' => $user]);
        $request->merge(['user_auth' => $user]);

        $response = $controller->getTransactions($request);
        $responseData = json_decode($response->getContent(), true);

        $this->assertTrue($responseData['success']);
        $this->assertCount(1, $responseData['data']['data']);
        $this->assertEquals('TXN123456', $responseData['data']['data'][0]['transaction_id']);
        $this->assertEquals('deposito', $responseData['data']['data'][0]['tipo']);
        $this->assertEquals(1000.0, $responseData['data']['data'][0]['amount']);
    }

    public function test_deve_buscar_transacao_por_endtoendid_de_saque(): void
    {
        $user = User::factory()->create([
            'username' => 'testuser',
            'user_id' => 'testuser',
        ]);
        
        $this->createSolicitacaoCashOut([
            'user_id' => $user,
            'idTransaction' => 'TXN789012',
            'externalreference' => 'E2E345678',
            'amount' => 500.00,
            'cash_out_liquido' => 490.00,
            'taxa_cash_out' => 10.00,
            'beneficiaryname' => 'Maria Santos',
            'beneficiarydocument' => '98765432100',
        ]);

        $controller = new \App\Http\Controllers\Api\UserController();
        $request = \Illuminate\Http\Request::create('/api/transactions', 'GET', [
            'busca' => 'E2E345678',
            'page' => 1,
            'limit' => 10,
        ]);
        $request->setUserResolver(fn() => $user);
        $request->merge(['user_auth' => $user]);

        $response = $controller->getTransactions($request);
        $responseData = json_decode($response->getContent(), true);

        $this->assertTrue($responseData['success']);
        $this->assertCount(1, $responseData['data']['data']);
        $this->assertEquals('TXN789012', $responseData['data']['data'][0]['transaction_id']);
        $this->assertEquals('saque', $responseData['data']['data'][0]['tipo']);
        $this->assertEquals(500.0, $responseData['data']['data'][0]['amount']);
    }

    public function test_deve_buscar_transacao_por_nome_do_cliente(): void
    {
        $user = User::factory()->create([
            'username' => 'testuser',
            'user_id' => 'testuser',
        ]);
        
        $this->createSolicitacao([
            'user_id' => $user,
            'idTransaction' => 'TXN111',
            'externalreference' => 'EXT111',
            'amount' => 200.00,
            'deposito_liquido' => 195.00,
            'taxa_cash_in' => 5.00,
            'client_name' => 'Pedro Oliveira',
            'client_document' => '11122233344',
            'client_email' => 'pedro@test.com',
        ]);

        $controller = new \App\Http\Controllers\Api\UserController();
        $request = \Illuminate\Http\Request::create('/api/transactions', 'GET', [
            'busca' => 'Pedro',
            'page' => 1,
            'limit' => 10,
        ]);
        $request->setUserResolver(fn() => $user);
        $request->merge(['user_auth' => $user]);

        $response = $controller->getTransactions($request);
        $responseData = json_decode($response->getContent(), true);

        $this->assertTrue($responseData['success']);
        $this->assertCount(1, $responseData['data']['data']);
        $this->assertStringContainsString('Pedro', $responseData['data']['data'][0]['nome_cliente']);
    }

    public function test_deve_filtrar_por_tipo_deposito(): void
    {
        $user = User::factory()->create([
            'username' => 'testuser',
            'user_id' => 'testuser',
        ]);
        
        $this->createSolicitacao([
            'user_id' => $user,
            'idTransaction' => 'DEP001',
            'externalreference' => 'EXT001',
        ]);

        $this->createSolicitacaoCashOut([
            'user_id' => $user,
            'idTransaction' => 'SAQ001',
            'externalreference' => 'EXT002',
        ]);

        $controller = new \App\Http\Controllers\Api\UserController();
        $request = \Illuminate\Http\Request::create('/api/transactions', 'GET', [
            'tipo' => 'deposito',
            'page' => 1,
            'limit' => 10,
        ]);
        $request->setUserResolver(fn() => $user);
        $request->merge(['user_auth' => $user]);

        $response = $controller->getTransactions($request);
        $responseData = json_decode($response->getContent(), true);

        $this->assertTrue($responseData['success']);
        $this->assertCount(1, $responseData['data']['data']);
        $this->assertEquals('deposito', $responseData['data']['data'][0]['tipo']);
    }

    public function test_deve_filtrar_por_tipo_saque(): void
    {
        $user = User::factory()->create([
            'username' => 'testuser',
            'user_id' => 'testuser',
        ]);
        
        $this->createSolicitacao([
            'user_id' => $user,
            'idTransaction' => 'DEP001',
            'externalreference' => 'EXT001',
        ]);

        $this->createSolicitacaoCashOut([
            'user_id' => $user,
            'idTransaction' => 'SAQ001',
            'externalreference' => 'EXT002',
        ]);

        $controller = new \App\Http\Controllers\Api\UserController();
        $request = \Illuminate\Http\Request::create('/api/transactions', 'GET', [
            'tipo' => 'saque',
            'page' => 1,
            'limit' => 10,
        ]);
        $request->setUserResolver(fn() => $user);
        $request->merge(['user_auth' => $user]);

        $response = $controller->getTransactions($request);
        $responseData = json_decode($response->getContent(), true);

        $this->assertTrue($responseData['success']);
        $this->assertCount(1, $responseData['data']['data']);
        $this->assertEquals('saque', $responseData['data']['data'][0]['tipo']);
    }

    public function test_deve_retornar_vazio_quando_nao_encobra_transacao(): void
    {
        $user = User::factory()->create(['username' => 'testuser']);

        $controller = new \App\Http\Controllers\Api\UserController();
        $request = \Illuminate\Http\Request::create('/api/transactions', 'GET', [
            'busca' => 'ID_INEXISTENTE',
            'page' => 1,
            'limit' => 10,
        ]);
        $request->setUserResolver(fn() => $user);
        $request->merge(['user_auth' => $user]);

        $response = $controller->getTransactions($request);
        $responseData = json_decode($response->getContent(), true);

        $this->assertTrue($responseData['success']);
        $this->assertCount(0, $responseData['data']['data']);
        $this->assertEquals(0, $responseData['data']['total']);
    }

    public function test_deve_validar_limite_maximo_de_50_itens_por_pagina(): void
    {
        $user = User::factory()->create(['username' => 'testuser']);

        $controller = new \App\Http\Controllers\Api\UserController();
        $request = \Illuminate\Http\Request::create('/api/transactions', 'GET', [
            'page' => 1,
            'limit' => 100, // Tentar passar 100, deve limitar a 50
        ]);
        $request->setUserResolver(fn() => $user);
        $request->merge(['user_auth' => $user]);

        $response = $controller->getTransactions($request);
        $responseData = json_decode($response->getContent(), true);

        $this->assertTrue($responseData['success']);
        $this->assertLessThanOrEqual(50, $responseData['data']['per_page']);
    }

    public function test_deve_retornar_erro_401_quando_usuario_nao_autenticado(): void
    {
        $controller = new \App\Http\Controllers\Api\UserController();
        $request = \Illuminate\Http\Request::create('/api/transactions', 'GET');

        $response = $controller->getTransactions($request);
        $responseData = json_decode($response->getContent(), true);

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertFalse($responseData['success']);
        $this->assertStringContainsString('autenticado', $responseData['message']);
    }

    public function test_deve_aplicar_paginacao_corretamente(): void
    {
        $user = User::factory()->create([
            'username' => 'testuser',
            'user_id' => 'testuser',
        ]);
        
        // Criar 25 transações
        for ($i = 1; $i <= 25; $i++) {
            $this->createSolicitacao([
                'user_id' => $user,
                'idTransaction' => "TXN{$i}",
                'externalreference' => "EXT{$i}",
                'amount' => 100.00 * $i,
                'deposito_liquido' => 97.50 * $i,
                'taxa_cash_in' => 2.50 * $i,
                'date' => now()->subDays($i),
                'client_name' => "Cliente {$i}",
                'client_document' => str_pad((string)$i, 11, '0', STR_PAD_LEFT),
                'client_email' => "cliente{$i}@test.com",
            ]);
        }

        $controller = new \App\Http\Controllers\Api\UserController();
        $request = \Illuminate\Http\Request::create('/api/transactions', 'GET', [
            'page' => 2,
            'limit' => 10,
        ]);
        $request->setUserResolver(fn() => $user);
        $request->merge(['user_auth' => $user]);

        $response = $controller->getTransactions($request);
        $responseData = json_decode($response->getContent(), true);

        $this->assertTrue($responseData['success']);
        $this->assertEquals(2, $responseData['data']['current_page']);
        $this->assertEquals(10, $responseData['data']['per_page']);
        $this->assertEquals(25, $responseData['data']['total']);
        $this->assertEquals(3, $responseData['data']['last_page']);
    }

    public function test_deve_ordenar_transacoes_por_data_descendente(): void
    {
        $user = User::factory()->create([
            'username' => 'testuser',
            'user_id' => 'testuser',
        ]);
        
        $this->createSolicitacao([
            'user_id' => $user,
            'idTransaction' => 'TXN_OLD',
            'externalreference' => 'EXT_OLD',
            'date' => now()->subDays(5),
            'client_name' => 'Cliente Antigo',
            'client_document' => '11111111111',
        ]);

        $this->createSolicitacao([
            'user_id' => $user,
            'idTransaction' => 'TXN_NEW',
            'externalreference' => 'EXT_NEW',
            'amount' => 200.00,
            'deposito_liquido' => 195.00,
            'taxa_cash_in' => 5.00,
            'client_name' => 'Cliente Novo',
            'client_document' => '22222222222',
        ]);

        $controller = new \App\Http\Controllers\Api\UserController();
        $request = \Illuminate\Http\Request::create('/api/transactions', 'GET', [
            'page' => 1,
            'limit' => 10,
        ]);
        $request->setUserResolver(fn() => $user);
        $request->merge(['user_auth' => $user]);

        $response = $controller->getTransactions($request);
        $responseData = json_decode($response->getContent(), true);

        $this->assertTrue($responseData['success']);
        $this->assertCount(2, $responseData['data']['data']);
        $this->assertEquals('TXN_NEW', $responseData['data']['data'][0]['transaction_id']);
        $this->assertEquals('TXN_OLD', $responseData['data']['data'][1]['transaction_id']);
    }

    public function test_deve_filtrar_transacoes_apenas_do_usuario_autenticado(): void
    {
        $user1 = User::factory()->create([
            'username' => 'user1',
            'user_id' => 'user1',
        ]);
        $user2 = User::factory()->create([
            'username' => 'user2',
            'user_id' => 'user2',
        ]);
        
        $this->createSolicitacao([
            'user_id' => $user1->user_id,
            'idTransaction' => 'TXN_USER1',
            'externalreference' => 'EXT_USER1',
            'client_name' => 'Cliente User1',
            'client_document' => '11111111111',
        ]);

        $this->createSolicitacao([
            'user_id' => $user2->user_id,
            'idTransaction' => 'TXN_USER2',
            'externalreference' => 'EXT_USER2',
            'amount' => 200.00,
            'deposito_liquido' => 195.00,
            'taxa_cash_in' => 5.00,
            'client_name' => 'Cliente User2',
            'client_document' => '22222222222',
        ]);

        $controller = new \App\Http\Controllers\Api\UserController();
        $request = \Illuminate\Http\Request::create('/api/transactions', 'GET', [
            'page' => 1,
            'limit' => 10,
        ]);
        $request->setUserResolver(fn() => $user1);

        $response = $controller->getTransactions($request);
        $responseData = json_decode($response->getContent(), true);

        $this->assertTrue($responseData['success']);
        $this->assertCount(1, $responseData['data']['data']);
        $this->assertEquals('TXN_USER1', $responseData['data']['data'][0]['transaction_id']);
    }
}










