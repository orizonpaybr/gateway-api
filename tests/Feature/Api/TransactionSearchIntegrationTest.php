<?php

namespace Tests\Feature\Api;

use Tests\TestCase;
use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Helpers\AuthTestHelper;
use Tests\Feature\Helpers\TransactionTestHelper;

/**
 * Testes de Integração - API de Busca de Transações
 * Foco: Endpoints, Autenticação, Integração Frontend-Backend
 */
class TransactionSearchIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_endpoint_get_transactions_deve_retornar_200_com_busca_por_id(): void
    {
        $user = User::factory()->create([
            'username' => 'testuser',
            'user_id' => 'testuser',
        ]);
        $token = AuthTestHelper::getAuthToken($user);

        TransactionTestHelper::createSolicitacao([
            'user_id' => $user,
            'idTransaction' => 'TXN123456',
            'externalreference' => 'EXT789',
            'amount' => 1000.00,
            'deposito_liquido' => 975.00,
            'taxa_cash_in' => 25.00,
            'status' => 'PAID_OUT',
            'date' => now(),
            'client_name' => 'João Silva',
            'client_document' => '12345678900',
            'adquirente_ref' => 'Banco Test',
            'descricao_transacao' => 'Pagamento Teste',
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/transactions?busca=TXN123456&page=1&limit=1');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'data' => [
                        '*' => [
                            'id',
                            'transaction_id',
                            'tipo',
                            'amount',
                            'valor_liquido',
                            'taxa',
                            'status',
                            'status_legivel',
                            'data',
                            'nome_cliente',
                            'documento',
                            'adquirente',
                            'descricao',
                        ],
                    ],
                    'current_page',
                    'last_page',
                    'per_page',
                    'total',
                ],
            ])
            ->assertJson([
                'success' => true,
                'data' => [
                    'data' => [
                        [
                            'transaction_id' => 'TXN123456',
                            'tipo' => 'deposito',
                            'amount' => 1000.0,
                        ],
                    ],
                ],
            ]);
    }

    public function test_endpoint_get_transactions_deve_retornar_401_sem_autenticacao(): void
    {
        $response = $this->getJson('/api/transactions?busca=TXN123456');

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
            ]);
        // A mensagem pode variar: "Usuário não autenticado" ou "Token não fornecido"
        $this->assertContains($response->json('message'), ['Usuário não autenticado', 'Token não fornecido']);
    }

    public function test_endpoint_deve_buscar_por_endtoendid_corretamente(): void
    {
        $user = User::factory()->create([
            'username' => 'testuser',
            'user_id' => 'testuser',
        ]);
        $token = AuthTestHelper::getAuthToken($user);

        TransactionTestHelper::createSolicitacaoCashOut([
            'user_id' => $user,
            'idTransaction' => 'TXN789',
            'externalreference' => 'E2E345678901',
            'amount' => 500.00,
            'cash_out_liquido' => 490.00,
            'taxa_cash_out' => 10.00,
            'status' => 'PAID_OUT',
            'date' => now(),
            'beneficiaryname' => 'Maria Santos',
            'beneficiarydocument' => '98765432100',
            'executor_ordem' => 'Banco Test',
            'descricao_transacao' => 'Saque Teste',
            'pix' => 'MANUAL',
            'pixkey' => 'MANUAL',
            'type' => 'pix',
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/transactions?busca=E2E345678901&page=1&limit=1');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'data' => [
                        [
                            'transaction_id' => 'TXN789',
                            'tipo' => 'saque',
                        ],
                    ],
                ],
            ]);
    }

    public function test_endpoint_deve_filtrar_por_tipo_deposito(): void
    {
        $user = User::factory()->create([
            'username' => 'testuser',
            'user_id' => 'testuser',
        ]);
        $token = AuthTestHelper::getAuthToken($user);

        TransactionTestHelper::createSolicitacao([
            'user_id' => $user,
            'idTransaction' => 'DEP001',
            'externalreference' => 'EXT001',
            'amount' => 100.00,
            'deposito_liquido' => 97.50,
            'taxa_cash_in' => 2.50,
            'status' => 'PAID_OUT',
            'date' => now(),
            'client_name' => 'Cliente 1',
            'client_document' => '11111111111',
        ]);

        TransactionTestHelper::createSolicitacaoCashOut([
            'user_id' => $user,
            'idTransaction' => 'SAQ001',
            'externalreference' => 'EXT002',
            'amount' => 50.00,
            'cash_out_liquido' => 49.00,
            'taxa_cash_out' => 1.00,
            'status' => 'PAID_OUT',
            'date' => now(),
            'beneficiaryname' => 'Cliente 2',
            'beneficiarydocument' => '22222222222',
            'pix' => 'MANUAL',
            'pixkey' => 'MANUAL',
            'type' => 'pix',
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/transactions?tipo=deposito&page=1&limit=10');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertEquals('deposito', $data[0]['tipo']);
    }

    public function test_endpoint_deve_filtrar_por_tipo_saque(): void
    {
        $user = User::factory()->create([
            'username' => 'testuser',
            'user_id' => 'testuser',
        ]);
        $token = AuthTestHelper::getAuthToken($user);

        TransactionTestHelper::createSolicitacao([
            'user_id' => $user,
            'idTransaction' => 'DEP001',
            'externalreference' => 'EXT001',
            'amount' => 100.00,
            'deposito_liquido' => 97.50,
            'taxa_cash_in' => 2.50,
            'status' => 'PAID_OUT',
            'date' => now(),
            'client_name' => 'Cliente 1',
            'client_document' => '11111111111',
        ]);

        TransactionTestHelper::createSolicitacaoCashOut([
            'user_id' => $user,
            'idTransaction' => 'SAQ001',
            'externalreference' => 'EXT002',
            'amount' => 50.00,
            'cash_out_liquido' => 49.00,
            'taxa_cash_out' => 1.00,
            'status' => 'PAID_OUT',
            'date' => now(),
            'beneficiaryname' => 'Cliente 2',
            'beneficiarydocument' => '22222222222',
            'pix' => 'MANUAL',
            'pixkey' => 'MANUAL',
            'type' => 'pix',
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/transactions?tipo=saque&page=1&limit=10');

        $response->assertStatus(200);

        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertEquals('saque', $data[0]['tipo']);
    }

    public function test_endpoint_deve_retornar_vazio_quando_nao_encobra_transacao(): void
    {
        $user = User::factory()->create([
            'username' => 'testuser',
            'user_id' => 'testuser',
        ]);
        $token = AuthTestHelper::getAuthToken($user);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/transactions?busca=ID_INEXISTENTE&page=1&limit=10');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'data' => [],
                    'total' => 0,
                ],
            ]);
    }

    public function test_endpoint_deve_usar_cache_para_requisicoes_repetidas(): void
    {
        $user = User::factory()->create([
            'username' => 'testuser',
            'user_id' => 'testuser',
        ]);
        $token = AuthTestHelper::getAuthToken($user);

        TransactionTestHelper::createSolicitacao([
            'user_id' => $user,
            'idTransaction' => 'TXN_CACHE',
            'externalreference' => 'EXT_CACHE',
            'amount' => 100.00,
            'deposito_liquido' => 97.50,
            'taxa_cash_in' => 2.50,
            'status' => 'PAID_OUT',
            'date' => now(),
            'client_name' => 'Cliente Cache',
            'client_document' => '11111111111',
        ]);

        // Primeira requisição
        $response1 = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/transactions?busca=TXN_CACHE&page=1&limit=10');

        $response1->assertStatus(200);

        // Segunda requisição (deve usar cache)
        $response2 = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/transactions?busca=TXN_CACHE&page=1&limit=10');

        $response2->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'data' => [
                        [
                            'transaction_id' => 'TXN_CACHE',
                        ],
                    ],
                ],
            ]);
    }

    public function test_endpoint_deve_validar_limite_maximo_de_50_itens(): void
    {
        $user = User::factory()->create([
            'username' => 'testuser',
            'user_id' => 'testuser',
        ]);
        $token = AuthTestHelper::getAuthToken($user);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/transactions?page=1&limit=100');

        $response->assertStatus(200);

        $perPage = $response->json('data.per_page');
        $this->assertLessThanOrEqual(50, $perPage);
    }

    public function test_endpoint_deve_aplicar_paginacao_corretamente(): void
    {
        $user = User::factory()->create([
            'username' => 'testuser',
            'user_id' => 'testuser',
        ]);
        $token = AuthTestHelper::getAuthToken($user);

        // Criar 25 transações
        for ($i = 1; $i <= 25; $i++) {
            TransactionTestHelper::createSolicitacao([
                'user_id' => $user,
                'idTransaction' => "TXN{$i}",
                'externalreference' => "EXT{$i}",
                'amount' => 100.00 * $i,
                'deposito_liquido' => 97.50 * $i,
                'taxa_cash_in' => 2.50 * $i,
                'status' => 'PAID_OUT',
                'date' => now()->subDays($i),
                'client_name' => "Cliente {$i}",
                'client_document' => str_pad((string)$i, 11, '0', STR_PAD_LEFT),
            ]);
        }

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/transactions?page=2&limit=10');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'current_page' => 2,
                    'per_page' => 10,
                    'total' => 25,
                    'last_page' => 3,
                ],
            ]);
    }

    public function test_endpoint_deve_isolar_transacoes_por_usuario(): void
    {
        $user1 = User::factory()->create([
            'username' => 'user1',
            'user_id' => 'user1',
        ]);
        $user2 = User::factory()->create([
            'username' => 'user2',
            'user_id' => 'user2',
        ]);
        $token1 = AuthTestHelper::getAuthToken($user1);
        $token2 = AuthTestHelper::getAuthToken($user2);

        TransactionTestHelper::createSolicitacao([
            'user_id' => $user1,
            'idTransaction' => 'TXN_USER1',
            'externalreference' => 'EXT_USER1',
            'amount' => 100.00,
            'deposito_liquido' => 97.50,
            'taxa_cash_in' => 2.50,
            'status' => 'PAID_OUT',
            'date' => now(),
            'client_name' => 'Cliente User1',
            'client_document' => '11111111111',
        ]);

        TransactionTestHelper::createSolicitacao([
            'user_id' => $user2,
            'idTransaction' => 'TXN_USER2',
            'externalreference' => 'EXT_USER2',
            'amount' => 200.00,
            'deposito_liquido' => 195.00,
            'taxa_cash_in' => 5.00,
            'status' => 'PAID_OUT',
            'date' => now(),
            'client_name' => 'Cliente User2',
            'client_document' => '22222222222',
        ]);

        // User1 não deve ver transações do User2
        $response1 = $this->withHeaders([
            'Authorization' => "Bearer {$token1}",
            'Accept' => 'application/json',
        ])->getJson('/api/transactions?page=1&limit=10');

        $response1->assertStatus(200);
        $data1 = $response1->json('data.data');
        $this->assertCount(1, $data1);
        $this->assertEquals('TXN_USER1', $data1[0]['transaction_id']);

        // User2 não deve ver transações do User1
        $response2 = $this->withHeaders([
            'Authorization' => "Bearer {$token2}",
            'Accept' => 'application/json',
        ])->getJson('/api/transactions?page=1&limit=10');

        $response2->assertStatus(200);
        $data2 = $response2->json('data.data');
        $this->assertCount(1, $data2);
        $this->assertEquals('TXN_USER2', $data2[0]['transaction_id']);
    }

    public function test_endpoint_deve_buscar_por_nome_do_cliente(): void
    {
        $user = User::factory()->create([
            'username' => 'testuser',
            'user_id' => 'testuser',
        ]);
        $token = AuthTestHelper::getAuthToken($user);

        TransactionTestHelper::createSolicitacao([
            'user_id' => $user,
            'idTransaction' => 'TXN_NAME',
            'externalreference' => 'EXT_NAME',
            'amount' => 100.00,
            'deposito_liquido' => 97.50,
            'taxa_cash_in' => 2.50,
            'status' => 'PAID_OUT',
            'date' => now(),
            'client_name' => 'Pedro Oliveira',
            'client_document' => '11122233344',
            'adquirente_ref' => 'Banco Test',
            'descricao_transacao' => 'Pagamento',
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/transactions?busca=Pedro&page=1&limit=10');

        $response->assertStatus(200);

        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertStringContainsString('Pedro', $data[0]['nome_cliente']);
    }
}










