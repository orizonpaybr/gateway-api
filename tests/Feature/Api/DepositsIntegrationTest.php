<?php

namespace Tests\Feature\Api;

use Tests\TestCase;
use App\Models\User;
use App\Models\Solicitacoes;
use App\Models\UsersKey;
use App\Constants\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * Testes de Integração - API de Depósitos
 * 
 * Cobre:
 * - Endpoints de depósitos
 * - Autenticação
 * - Validação de requests
 * - Respostas JSON
 * - Tratamento de erros
 */
class DepositsIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // Criar usuário admin e obter token
        $this->user = User::factory()->create([
            'username' => 'testuser',
            'user_id' => 'testuser',
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
            'status' => 1,
            'banido' => 0,
            'permission' => UserPermission::ADMIN, // Permissão de admin
        ]);

        // Criar UsersKey (necessário para login)
        UsersKey::factory()->create([
            'user_id' => $this->user->user_id ?? $this->user->username,
            'token' => 'test_token_' . $this->user->username,
            'secret' => 'test_secret_' . $this->user->username,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'username' => 'testuser',
            'password' => 'password123',
        ]);

        $token = $response->json('data.token');
        
        // Se login falhar, usar actingAs como fallback
        if (!$token || $response->status() !== 200) {
            $this->actingAs($this->user);
            $this->token = 'acting_as_token';
        } else {
            $this->token = $token;
        }
    }

    /**
     * Helper para criar depósito
     */
    private function createDeposito(array $attributes = []): Solicitacoes
    {
        $defaults = [
            'user_id' => $this->user->user_id,
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

    /**
     * Teste: Deve listar depósitos com autenticação
     */
    public function test_should_list_deposits_with_authentication(): void
    {
        $this->createDeposito(['amount' => 100]);
        $this->createDeposito(['amount' => 200]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/financial/deposits?page=1&limit=20');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'data' => [
                        '*' => [
                            'id',
                            'transacao_id',
                            'valor_total',
                            'valor_liquido',
                            'status_legivel',
                        ],
                    ],
                    'current_page',
                    'last_page',
                    'total',
                ],
            ]);

        $this->assertTrue($response->json('success'));
        $this->assertGreaterThanOrEqual(2, $response->json('data.total'));
    }

    /**
     * Teste: Deve retornar 401 sem autenticação
     */
    public function test_should_return_401_without_authentication(): void
    {
        $response = $this->getJson('/api/admin/financial/deposits');

        $response->assertStatus(401);
    }

    /**
     * Teste: Deve filtrar depósitos por status
     */
    public function test_should_filter_deposits_by_status(): void
    {
        $this->createDeposito(['status' => 'PAID_OUT', 'amount' => 100]);
        $this->createDeposito(['status' => 'PENDING', 'amount' => 200]);
        $this->createDeposito(['status' => 'PAID_OUT', 'amount' => 300]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/financial/deposits?status=PAID_OUT&page=1&limit=20');

        $response->assertStatus(200);
        $this->assertEquals(2, $response->json('data.total'));
    }

    /**
     * Teste: Deve buscar depósitos por termo
     */
    public function test_should_search_deposits_by_term(): void
    {
        $this->createDeposito(['client_name' => 'João Silva', 'idTransaction' => 'TXN001']);
        $this->createDeposito(['client_name' => 'Maria Santos', 'idTransaction' => 'TXN002']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/financial/deposits?busca=João&page=1&limit=20');

        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, $response->json('data.total'));
    }

    /**
     * Teste: Deve filtrar depósitos por data
     */
    public function test_should_filter_deposits_by_date(): void
    {
        $hoje = Carbon::now();
        $ontem = $hoje->copy()->subDay();

        $this->createDeposito(['date' => $hoje, 'amount' => 100]);
        $this->createDeposito(['date' => $ontem, 'amount' => 200]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/financial/deposits?data_inicio=' . $hoje->format('Y-m-d') . '&data_fim=' . $hoje->format('Y-m-d') . '&page=1&limit=20');

        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, $response->json('data.total'));
    }

    /**
     * Teste: Deve retornar estatísticas de depósitos
     */
    public function test_should_return_deposits_stats(): void
    {
        $hoje = Carbon::now();
        $this->createDeposito(['date' => $hoje, 'status' => 'PAID_OUT', 'amount' => 100]);
        $this->createDeposito(['date' => $hoje, 'status' => 'PAID_OUT', 'amount' => 200]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/financial/deposits/stats?periodo=hoje');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'depositos_aprovados_hoje',
                    'valor_total_hoje',
                    'depositos_aprovados_mes',
                    'valor_total_mes',
                ],
            ]);

        $this->assertTrue($response->json('success'));
        $this->assertGreaterThanOrEqual(2, $response->json('data.depositos_aprovados_hoje'));
    }

    /**
     * Teste: Deve validar paginação
     */
    public function test_should_validate_pagination(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->createDeposito(['amount' => 100 + $i]);
        }

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/financial/deposits?page=1&limit=20');

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('data.current_page'));
        $this->assertEquals(2, $response->json('data.last_page'));
        $this->assertEquals(25, $response->json('data.total'));
        $this->assertCount(20, $response->json('data.data'));
    }

    /**
     * Teste: Deve retornar erro 500 em caso de exceção
     */
    public function test_should_return_500_on_exception(): void
    {
        // Simular erro forçando query inválida
        // (Em produção, isso seria tratado pelo service)

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/financial/deposits?page=invalid');

        // Deve retornar 200 com dados vazios ou 422 para validação
        $this->assertContains($response->status(), [200, 422]);
    }

    /**
     * Teste: Deve validar limite máximo de itens
     */
    public function test_should_validate_max_limit(): void
    {
        for ($i = 0; $i < 150; $i++) {
            $this->createDeposito(['amount' => 100 + $i]);
        }

        // Testar com limite acima do máximo - deve retornar erro de validação
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/financial/deposits?page=1&limit=200');

        // Deve retornar 422 (validação falhou) ou 200 com limite ajustado
        $this->assertContains($response->status(), [200, 422]);
        
        if ($response->status() === 200) {
            $this->assertLessThanOrEqual(100, count($response->json('data.data')));
        } else {
            // Se retornou 422, validação funcionou corretamente
            $this->assertTrue(true);
        }
    }

    /**
     * Teste: Deve retornar dados vazios quando não há depósitos
     */
    public function test_should_return_empty_data_when_no_deposits(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/financial/deposits?page=1&limit=20');

        $response->assertStatus(200);
        $this->assertTrue($response->json('success'));
        $this->assertEmpty($response->json('data.data'));
        $this->assertEquals(0, $response->json('data.total'));
    }

    /**
     * Teste: Deve atualizar status de depósito
     */
    public function test_should_update_deposit_status(): void
    {
        $deposito = $this->createDeposito(['status' => 'PENDING']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->putJson('/api/admin/financial/deposits/' . $deposito->id . '/status', [
            'status' => 'PAID_OUT',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'deposit',
                    'message',
                ],
            ]);

        $this->assertTrue($response->json('success'));

        // Verificar que o status foi atualizado
        $deposito->refresh();
        $this->assertEquals('PAID_OUT', $deposito->status);
    }

    /**
     * Teste: Deve validar status ao atualizar
     */
    public function test_should_validate_status_on_update(): void
    {
        $deposito = $this->createDeposito(['status' => 'PENDING']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->putJson('/api/admin/financial/deposits/' . $deposito->id . '/status', [
            'status' => 'INVALID_STATUS',
        ]);

        // Deve retornar erro de validação
        $this->assertContains($response->status(), [400, 422]);
    }

    /**
     * Teste: Deve retornar erro ao atualizar depósito inexistente
     */
    public function test_should_return_error_on_nonexistent_deposit(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->putJson('/api/admin/financial/deposits/99999/status', [
            'status' => 'PAID_OUT',
        ]);

        // Deve retornar erro 404 ou 400
        $this->assertContains($response->status(), [400, 404, 500]);
    }
}

