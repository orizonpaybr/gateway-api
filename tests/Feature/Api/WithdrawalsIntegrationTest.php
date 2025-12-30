<?php

namespace Tests\Feature\Api;

use Tests\TestCase;
use App\Models\User;
use App\Models\SolicitacoesCashOut;
use App\Models\UsersKey;
use App\Constants\UserPermission;
use Tests\Feature\Helpers\AuthTestHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * Testes de Integração - API de Saques
 * 
 * Cobre:
 * - Endpoints de saques
 * - Autenticação
 * - Validação de requests
 * - Respostas JSON
 * - Tratamento de erros
 */
class WithdrawalsIntegrationTest extends TestCase
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
            'permission' => UserPermission::ADMIN,
        ]);

        // Criar UsersKey (necessário para login)
        UsersKey::factory()->create([
            'user_id' => $this->user->user_id ?? $this->user->username,
            'token' => 'test_token_' . $this->user->username,
            'secret' => 'test_secret_' . $this->user->username,
        ]);

        // Obter token usando AuthTestHelper
        $this->token = AuthTestHelper::generateTestToken($this->user);
    }

    /**
     * Helper para criar saque
     */
    private function createSaque(array $attributes = []): SolicitacoesCashOut
    {
        $defaults = [
            'user_id' => $this->user->user_id,
            'idTransaction' => 'TXN' . uniqid(),
            'externalreference' => 'EXT' . uniqid(),
            'amount' => 100.00,
            'cash_out_liquido' => 97.50,
            'taxa_cash_out' => 2.50,
            'status' => 'PAID_OUT',
            'date' => now(),
            'pix' => 'test@example.com',
            'pixkey' => 'test@example.com',
            'type' => 'EMAIL',
            'beneficiaryname' => 'Cliente Test',
            'beneficiarydocument' => '12345678900',
            'descricao_transacao' => 'Saque de teste',
        ];

        return SolicitacoesCashOut::create(array_merge($defaults, $attributes));
    }

    /**
     * Teste: Deve listar saques com autenticação
     */
    public function test_should_list_withdrawals_with_authentication(): void
    {
        $this->createSaque();
        $this->createSaque();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/financial/withdrawals');

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
                            'taxa',
                            'status',
                            'status_legivel',
                            'data',
                        ],
                    ],
                    'current_page',
                    'last_page',
                    'per_page',
                    'total',
                ],
            ]);

        $this->assertEquals(2, $response->json('data.total'));
    }

    /**
     * Teste: Deve retornar 401 sem autenticação
     */
    public function test_should_return_401_without_authentication(): void
    {
        $response = $this->getJson('/api/admin/financial/withdrawals');

        $response->assertStatus(401);
    }

    /**
     * Teste: Deve filtrar saques por status
     */
    public function test_should_filter_withdrawals_by_status(): void
    {
        $this->createSaque(['status' => 'PAID_OUT']);
        $this->createSaque(['status' => 'PENDING']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/financial/withdrawals?status=PAID_OUT');

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('data.total'));
        $this->assertEquals('PAID_OUT', $response->json('data.data.0.status'));
    }

    /**
     * Teste: Deve buscar saques por termo
     */
    public function test_should_search_withdrawals_by_term(): void
    {
        $this->createSaque(['pixkey' => 'test@example.com']);
        $this->createSaque(['pixkey' => 'other@example.com']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/financial/withdrawals?busca=test@example.com');

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('data.total'));
    }

    /**
     * Teste: Deve filtrar saques por data
     */
    public function test_should_filter_withdrawals_by_date(): void
    {
        $hoje = Carbon::now();
        $this->createSaque(['date' => $hoje]);
        $this->createSaque(['date' => $hoje->copy()->subDays(5)]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/financial/withdrawals?data_inicio=' . $hoje->format('Y-m-d') . '&data_fim=' . $hoje->format('Y-m-d'));

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('data.total'));
    }

    /**
     * Teste: Deve retornar estatísticas de saques
     */
    public function test_should_return_withdrawals_stats(): void
    {
        $hoje = Carbon::now();
        $this->createSaque(['date' => $hoje, 'status' => 'PAID_OUT', 'amount' => 100, 'taxa_cash_out' => 2.5]);
        $this->createSaque(['date' => $hoje, 'status' => 'COMPLETED', 'amount' => 200, 'taxa_cash_out' => 5.0]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/financial/withdrawals/stats?periodo=hoje');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'saques_aprovados_hoje',
                    'valor_total_hoje',
                    'lucro_total_hoje',
                ],
            ]);

        $this->assertEquals(2, $response->json('data.saques_aprovados_hoje'));
        $this->assertEquals(300.0, $response->json('data.valor_total_hoje'));
    }

    /**
     * Teste: Deve validar paginação
     */
    public function test_should_validate_pagination(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->createSaque();
        }

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/financial/withdrawals?page=1&limit=20');

        $response->assertStatus(200);
        $this->assertEquals(20, count($response->json('data.data')));
        $this->assertEquals(25, $response->json('data.total'));
        $this->assertEquals(2, $response->json('data.last_page'));
    }

    /**
     * Teste: Deve retornar 500 em caso de exceção
     */
    public function test_should_return_500_on_exception(): void
    {
        // Simular erro forçando uma query inválida
        \DB::shouldReceive('selectOne')->andThrow(new \Exception('Database error'));

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/financial/withdrawals/stats');

        // O endpoint deve tratar o erro e retornar 500
        $response->assertStatus(500);
    }

    /**
     * Teste: Deve validar limite máximo
     */
    public function test_should_validate_max_limit(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/financial/withdrawals?limit=150');

        // Deve retornar 422 ou limitar a 100
        $this->assertContains($response->status(), [200, 422]);
        if ($response->status() === 200) {
            $this->assertLessThanOrEqual(100, count($response->json('data.data')));
        }
    }

    /**
     * Teste: Deve retornar dados vazios quando não há saques
     */
    public function test_should_return_empty_data_when_no_withdrawals(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/financial/withdrawals');

        $response->assertStatus(200);
        $this->assertEquals(0, $response->json('data.total'));
        $this->assertEmpty($response->json('data.data'));
    }
}

