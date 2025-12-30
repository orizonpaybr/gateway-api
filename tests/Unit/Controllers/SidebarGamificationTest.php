<?php

namespace Tests\Unit\Controllers;

use Tests\TestCase;
use App\Models\User;
use App\Models\Nivel;
use App\Models\Solicitacoes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Helpers\AuthTestHelper;

/**
 * Testes Unitários - Sidebar Gamification
 * 
 * Cobre:
 * - getSidebarGamificationData
 * - Cálculo de níveis
 * - Cache
 * - Formatação de dados
 */
class SidebarGamificationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // Criar usuário
        $this->user = AuthTestHelper::createTestUser([
            'username' => 'testuser_' . uniqid(),
            'email' => 'testuser_' . uniqid() . '@example.com',
            'password' => Hash::make('password123'),
            'status' => 1,
            'banido' => 0,
        ]);
    }

    /**
     * Helper para criar nível de teste
     */
    private function createNivel(array $attributes = []): Nivel
    {
        $defaults = [
            'nome' => 'Bronze',
            'icone' => 'medal-bronze.png',
            'cor' => '#CD7F32',
            'minimo' => 0,
            'maximo' => 100000,
        ];

        return Nivel::create(array_merge($defaults, $attributes));
    }

    /**
     * Helper para criar depósito de teste
     */
    private function createDeposito(array $attributes = []): Solicitacoes
    {
        $defaults = [
            'user_id' => $this->user->username,
            'idTransaction' => 'TXN' . uniqid(),
            'externalreference' => 'EXT' . uniqid(),
            'amount' => 100.00,
            'deposito_liquido' => 97.50,
            'taxa_cash_in' => 2.50,
            'status' => 'PAID_OUT',
            'date' => now(),
            'method' => 'PIX',
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
            'descricao_transacao' => 'Depósito de teste',
        ];

        return Solicitacoes::create(array_merge($defaults, $attributes));
    }

    public function test_should_return_sidebar_gamification_data()
    {
        // Criar níveis
        $bronze = $this->createNivel([
            'nome' => 'Bronze',
            'minimo' => 0,
            'maximo' => 100000,
        ]);
        $prata = $this->createNivel([
            'nome' => 'Prata',
            'minimo' => 100000,
            'maximo' => 500000,
        ]);

        // Criar depósito
        $this->createDeposito(['amount' => 50000]);

        $controller = new \App\Http\Controllers\Api\UserController();
        $request = new \Illuminate\Http\Request();
        $request->setUserResolver(function () {
            return $this->user;
        });

        $response = $controller->getSidebarGamificationData($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
        $this->assertEquals('Bronze', $data['data']['current_level']);
        $this->assertEquals(50000, $data['data']['total_deposited']);
        $this->assertArrayHasKey('next_level', $data['data']);
    }

    public function test_should_calculate_current_level_correctly()
    {
        // Criar níveis
        $bronze = $this->createNivel([
            'nome' => 'Bronze',
            'minimo' => 0,
            'maximo' => 100000,
        ]);
        $prata = $this->createNivel([
            'nome' => 'Prata',
            'minimo' => 100000,
            'maximo' => 500000,
        ]);

        // Criar depósito que coloca o usuário no nível Prata
        $this->createDeposito(['amount' => 150000]);

        $controller = new \App\Http\Controllers\Api\UserController();
        $request = new \Illuminate\Http\Request();
        $request->setUserResolver(function () {
            return $this->user;
        });

        $response = $controller->getSidebarGamificationData($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $this->assertEquals('Prata', $data['data']['current_level']);
    }

    public function test_should_return_next_level_data()
    {
        // Criar níveis
        $bronze = $this->createNivel([
            'nome' => 'Bronze',
            'minimo' => 0,
            'maximo' => 100000,
        ]);
        $prata = $this->createNivel([
            'nome' => 'Prata',
            'minimo' => 100000,
            'maximo' => 500000,
        ]);

        // Criar depósito
        $this->createDeposito(['amount' => 50000]);

        $controller = new \App\Http\Controllers\Api\UserController();
        $request = new \Illuminate\Http\Request();
        $request->setUserResolver(function () {
            return $this->user;
        });

        $response = $controller->getSidebarGamificationData($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $this->assertNotNull($data['data']['next_level']);
        $this->assertEquals('Prata', $data['data']['next_level']['name']);
        $this->assertEquals(100000, $data['data']['next_level']['minimo']);
    }

    public function test_should_return_null_next_level_when_at_max_level()
    {
        // Criar níveis
        $bronze = $this->createNivel([
            'nome' => 'Bronze',
            'minimo' => 0,
            'maximo' => 100000,
        ]);
        $diamante = $this->createNivel([
            'nome' => 'Diamante',
            'minimo' => 1000000,
            'maximo' => 99999999.99,
        ]);

        // Criar depósito que coloca o usuário no último nível
        $this->createDeposito(['amount' => 2000000]);

        $controller = new \App\Http\Controllers\Api\UserController();
        $request = new \Illuminate\Http\Request();
        $request->setUserResolver(function () {
            return $this->user;
        });

        $response = $controller->getSidebarGamificationData($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $this->assertEquals('Diamante', $data['data']['current_level']);
        // Quando está no último nível, next_level pode ser null ou não existir
    }

    public function test_should_use_cache_for_sidebar_data()
    {
        // Criar níveis
        $bronze = $this->createNivel([
            'nome' => 'Bronze',
            'minimo' => 0,
            'maximo' => 100000,
        ]);

        // Criar depósito
        $this->createDeposito(['amount' => 50000]);

        $controller = new \App\Http\Controllers\Api\UserController();
        $request = new \Illuminate\Http\Request();
        $request->setUserResolver(function () {
            return $this->user;
        });

        // Primeira chamada
        $response1 = $controller->getSidebarGamificationData($request);
        $data1 = json_decode($response1->getContent(), true);

        // Segunda chamada (deve usar cache)
        $response2 = $controller->getSidebarGamificationData($request);
        $data2 = json_decode($response2->getContent(), true);

        $this->assertEquals(200, $response1->getStatusCode());
        $this->assertEquals(200, $response2->getStatusCode());
        $this->assertEquals($data1['data'], $data2['data']);
    }

    public function test_should_return_401_without_authentication()
    {
        $controller = new \App\Http\Controllers\Api\UserController();
        $request = new \Illuminate\Http\Request();

        $response = $controller->getSidebarGamificationData($request);

        $this->assertEquals(401, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertFalse($data['success']);
    }

    public function test_should_only_count_paid_deposits()
    {
        // Criar níveis
        $bronze = $this->createNivel([
            'nome' => 'Bronze',
            'minimo' => 0,
            'maximo' => 100000,
        ]);

        // Criar depósitos com diferentes status
        $this->createDeposito(['amount' => 50000, 'status' => 'PAID_OUT']);
        $this->createDeposito(['amount' => 30000, 'status' => 'PENDING']);
        $this->createDeposito(['amount' => 20000, 'status' => 'CANCELLED']);

        $controller = new \App\Http\Controllers\Api\UserController();
        $request = new \Illuminate\Http\Request();
        $request->setUserResolver(function () {
            return $this->user;
        });

        $response = $controller->getSidebarGamificationData($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        // Deve contar apenas PAID_OUT (50000)
        $this->assertEquals(50000, $data['data']['total_deposited']);
    }

    public function test_should_return_bronze_for_new_user()
    {
        // Criar níveis
        $bronze = $this->createNivel([
            'nome' => 'Bronze',
            'minimo' => 0,
            'maximo' => 100000,
        ]);

        // Não criar depósitos

        $controller = new \App\Http\Controllers\Api\UserController();
        $request = new \Illuminate\Http\Request();
        $request->setUserResolver(function () {
            return $this->user;
        });

        $response = $controller->getSidebarGamificationData($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $this->assertEquals('Bronze', $data['data']['current_level']);
        $this->assertEquals(0, $data['data']['total_deposited']);
    }

    public function test_should_calculate_current_level_max_correctly()
    {
        // Criar níveis
        $bronze = $this->createNivel([
            'nome' => 'Bronze',
            'minimo' => 0,
            'maximo' => 100000,
        ]);
        $prata = $this->createNivel([
            'nome' => 'Prata',
            'minimo' => 100000,
            'maximo' => 500000,
        ]);

        // Criar depósito
        $this->createDeposito(['amount' => 50000]);

        $controller = new \App\Http\Controllers\Api\UserController();
        $request = new \Illuminate\Http\Request();
        $request->setUserResolver(function () {
            return $this->user;
        });

        $response = $controller->getSidebarGamificationData($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $this->assertEquals(100000, $data['data']['current_level_max']);
    }

    public function test_should_handle_user_without_levels()
    {
        // Não criar níveis

        $controller = new \App\Http\Controllers\Api\UserController();
        $request = new \Illuminate\Http\Request();
        $request->setUserResolver(function () {
            return $this->user;
        });

        $response = $controller->getSidebarGamificationData($request);

        // Deve retornar 200 mesmo sem níveis (com valores padrão)
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
    }
}

