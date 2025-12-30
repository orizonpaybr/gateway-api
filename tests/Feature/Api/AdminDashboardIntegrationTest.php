<?php

namespace Tests\Feature\Api;

use Tests\TestCase;
use App\Models\User;
use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Helpers\AuthTestHelper;

/**
 * Testes de Integração - API Admin Dashboard
 * 
 * Cobre:
 * - Endpoints completos com autenticação admin
 * - Fluxos completos de dashboard
 * - Validação de dados
 * - Tratamento de erros
 */
class AdminDashboardIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // Criar usuário admin e obter token
        $this->adminUser = AuthTestHelper::createTestUser([
            'username' => 'admin_' . uniqid(),
            'email' => 'admin_' . uniqid() . '@example.com',
            'password' => Hash::make('password123'),
            'status' => 1,
            'banido' => 0,
            'permission' => 3, // Admin
        ]);

        $this->token = AuthTestHelper::generateTestToken($this->adminUser);
    }

    /**
     * Helper para criar depósito de teste
     */
    private function createDeposito(array $attributes = []): Solicitacoes
    {
        $defaults = [
            'user_id' => $this->adminUser->username,
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
     * Helper para criar saque de teste
     */
    private function createSaque(array $attributes = []): SolicitacoesCashOut
    {
        $amount = $attributes['amount'] ?? 50.00;
        $taxa = $attributes['taxa_cash_out'] ?? 2.00;
        $cashOutLiquido = $amount - $taxa;

        $defaults = [
            'user_id' => $this->adminUser->username,
            'idTransaction' => 'TXN_OUT' . uniqid(),
            'externalreference' => 'EXT_OUT' . uniqid(),
            'amount' => $amount,
            'taxa_cash_out' => $taxa,
            'cash_out_liquido' => $cashOutLiquido,
            'status' => 'COMPLETED',
            'date' => now(),
            'method' => 'PIX',
            'type' => 'pix',
            'client_name' => 'Cliente Test',
            'client_document' => '12345678900',
            'client_email' => 'cliente@test.com',
            'client_telefone' => '11999999999',
            'beneficiaryname' => 'Beneficiário Test',
            'beneficiarydocument' => '12345678900',
            'pix' => 'test@example.com',
            'pixkey' => 'test@example.com',
            'descricao_transacao' => 'Saque de teste',
        ];

        return SolicitacoesCashOut::create(array_merge($defaults, $attributes));
    }

    public function test_should_get_dashboard_stats_with_authentication()
    {
        // Criar transações
        $this->createDeposito(['amount' => 1000]);
        $this->createSaque(['amount' => 500]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/dashboard/stats?periodo=hoje');

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'data' => [
                    'periodo',
                    'financeiro',
                    'transacoes',
                    'usuarios',
                    'saques_pendentes',
                ],
            ]);
    }

    public function test_should_return_401_without_authentication()
    {
        $response = $this->getJson('/api/admin/dashboard/stats');

        $response->assertStatus(401);
    }

    public function test_should_return_403_for_non_admin_user()
    {
        // Criar usuário não-admin
        $nonAdminUser = AuthTestHelper::createTestUser([
            'username' => 'user_' . uniqid(),
            'email' => 'user_' . uniqid() . '@example.com',
            'permission' => 1, // Cliente
        ]);
        $nonAdminToken = AuthTestHelper::generateTestToken($nonAdminUser);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $nonAdminToken,
        ])->getJson('/api/admin/dashboard/stats');

        $response->assertStatus(403);
    }

    public function test_should_calculate_stats_based_on_period()
    {
        // Criar transações hoje
        $this->createDeposito(['amount' => 1000, 'date' => now()]);
        
        // Criar transações ontem
        $this->createDeposito(['amount' => 2000, 'date' => now()->subDay()]);

        // Buscar stats de hoje
        $responseHoje = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/dashboard/stats?periodo=hoje');

        $responseHoje->assertStatus(200);
        $dataHoje = $responseHoje->json('data');
        
        // Deve incluir apenas transações de hoje
        $this->assertGreaterThanOrEqual(1000, $dataHoje['transacoes']['depositos']['valor_total']);
    }

    public function test_should_get_user_stats()
    {
        // Criar usuários
        AuthTestHelper::createTestUser([
            'status' => 0,
            'email' => 'pendente_' . uniqid() . '@test.com',
            'username' => 'pendente_' . uniqid(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/dashboard/users-stats');

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'data' => [
                    'total_registrations',
                    'month_registrations',
                    'pending_registrations',
                    'banned_users',
                ],
            ]);
    }

    public function test_should_get_cache_metrics()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/dashboard/cache-metrics');

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'data' => [
                    'general',
                    'financial',
                ],
            ]);
    }

    public function test_should_get_recent_transactions()
    {
        // Criar transações
        $this->createDeposito(['amount' => 1000]);
        $this->createSaque(['amount' => 500]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/dashboard/transactions?limit=10');

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'data' => [
                    'transactions' => [
                        '*' => [
                            'id',
                            'type',
                            'amount',
                            'status',
                            'date',
                        ],
                    ],
                ],
            ]);
    }

    public function test_should_filter_transactions_by_type()
    {
        // Criar transações
        $this->createDeposito(['amount' => 1000]);
        $this->createSaque(['amount' => 500]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/dashboard/transactions?limit=10&type=deposit');

        $response->assertStatus(200);
        $transactions = $response->json('data.transactions');
        
        // Deve retornar apenas depósitos
        foreach ($transactions as $transaction) {
            $this->assertEquals('deposit', $transaction['type']);
        }
    }

    public function test_should_filter_transactions_by_status()
    {
        // Criar transações com diferentes status
        $this->createDeposito(['amount' => 1000, 'status' => 'PAID_OUT']);
        $this->createDeposito(['amount' => 2000, 'status' => 'PENDING']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/dashboard/transactions?limit=10&status=PAID_OUT');

        $response->assertStatus(200);
        $transactions = $response->json('data.transactions');
        
        // Deve retornar apenas transações com status PAID_OUT
        foreach ($transactions as $transaction) {
            $this->assertEquals('PAID_OUT', $transaction['status']);
        }
    }

    public function test_should_limit_transactions()
    {
        // Criar mais transações que o limite
        for ($i = 0; $i < 20; $i++) {
            $this->createDeposito(['amount' => 1000 + $i]);
        }

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/dashboard/transactions?limit=10');

        $response->assertStatus(200);
        $transactions = $response->json('data.transactions');
        
        // Deve retornar no máximo 10 transações
        $this->assertLessThanOrEqual(10, count($transactions));
    }

    public function test_should_use_cache_for_dashboard_stats()
    {
        // Criar transações
        $this->createDeposito(['amount' => 1000]);

        // Primeira requisição
        $response1 = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/dashboard/stats?periodo=hoje');

        // Segunda requisição (deve usar cache)
        $response2 = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/dashboard/stats?periodo=hoje');

        $response1->assertStatus(200);
        $response2->assertStatus(200);
        
        $data1 = $response1->json('data');
        $data2 = $response2->json('data');
        
        $this->assertEquals($data1, $data2);
    }

    public function test_should_handle_different_periods()
    {
        $periodos = ['hoje', 'ontem', '7dias', '30dias', 'mes_atual', 'mes_anterior', 'tudo'];

        foreach ($periodos as $periodo) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson("/api/admin/dashboard/stats?periodo={$periodo}");

            $response->assertStatus(200, "Falhou para período: {$periodo}");
            $data = $response->json();
            
            $this->assertTrue($data['success']);
            $this->assertArrayHasKey('data', $data);
        }
    }

    public function test_should_return_correct_structure()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/dashboard/stats?periodo=hoje');

        $response->assertStatus(200);
        $data = $response->json('data');
        
        $this->assertArrayHasKey('periodo', $data);
        $this->assertArrayHasKey('financeiro', $data);
        $this->assertArrayHasKey('transacoes', $data);
        $this->assertArrayHasKey('usuarios', $data);
        $this->assertArrayHasKey('saques_pendentes', $data);
    }
}

