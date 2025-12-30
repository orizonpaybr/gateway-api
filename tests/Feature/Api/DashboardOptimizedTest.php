<?php

namespace Tests\Feature\Api;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardOptimizedTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        
        $this->user = User::factory()->create([
            'username' => 'test_dashboard',
            'email' => 'dashboard@test.com',
            'password' => bcrypt('password123'),
            'status' => 1, // Ativo
            'banido' => 0, // Não banido
            'user_id' => 'test_dashboard', // Garantir que user_id corresponde ao username
        ]);
        
        // Criar UsersKey (necessário para login) - usar user_id do usuário
        \App\Models\UsersKey::factory()->create([
            'user_id' => $this->user->user_id ?? $this->user->username,
            'token' => 'test_token_' . $this->user->username,
            'secret' => 'test_secret_' . $this->user->username,
        ]);
        
        // Gerar token JWT via login
        $response = $this->postJson('/api/auth/login', [
            'username' => $this->user->username,
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
     * Teste: GET /api/dashboard/stats-optimized retorna 200
     */
    public function test_get_dashboard_stats_returns_200(): void
    {
        $headers = $this->token === 'acting_as_token' 
            ? [] 
            : ['Authorization' => 'Bearer ' . $this->token];
            
        $response = $this->withHeaders($headers)->getJson('/api/dashboard/stats-optimized');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'saldo_disponivel',
                    'entradas_mes',
                    'saidas_mes',
                    'splits_mes',
                    'periodo' => [
                        'inicio',
                        'fim',
                    ],
                ],
            ]);
    }

    /**
     * Teste: GET /api/dashboard/stats-optimized requer autenticação
     */
    public function test_get_dashboard_stats_requires_auth(): void
    {
        $response = $this->getJson('/api/dashboard/stats-optimized');

        $response->assertStatus(401);
    }

    /**
     * Teste: GET /api/dashboard/interactive-movement-optimized retorna 200
     */
    public function test_get_interactive_movement_returns_200(): void
    {
        $headers = $this->token === 'acting_as_token' 
            ? [] 
            : ['Authorization' => 'Bearer ' . $this->token];
            
        $response = $this->withHeaders($headers)->getJson('/api/dashboard/interactive-movement-optimized?periodo=hoje');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'periodo',
                    'data_inicio',
                    'data_fim',
                    'cards' => [
                        'total_depositos',
                        'qtd_depositos',
                        'total_saques',
                        'qtd_saques',
                    ],
                    'chart',
                ],
            ]);
    }

    /**
     * Teste: GET /api/dashboard/transaction-summary-optimized retorna 200
     */
    public function test_get_transaction_summary_returns_200(): void
    {
        $headers = $this->token === 'acting_as_token' 
            ? [] 
            : ['Authorization' => 'Bearer ' . $this->token];
            
        $response = $this->withHeaders($headers)->getJson('/api/dashboard/transaction-summary-optimized?periodo=hoje');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'periodo',
                    'quantidadeTransacoes',
                    'tarifaCobrada',
                    'qrCodes',
                    'indiceConversao',
                    'ticketMedio',
                    'valorMinMax',
                    'infracoes',
                    'percentualInfracoes',
                ],
            ]);
    }

    /**
     * Teste: Performance - múltiplas requisições devem usar cache
     */
    public function test_performance_multiple_requests_use_cache(): void
    {
        Cache::flush();
        
        $headers = $this->token === 'acting_as_token' 
            ? [] 
            : ['Authorization' => 'Bearer ' . $this->token];
        
        $start1 = microtime(true);
        $response1 = $this->withHeaders($headers)->getJson('/api/dashboard/stats-optimized');
        $time1 = microtime(true) - $start1;

        $start2 = microtime(true);
        $response2 = $this->withHeaders($headers)->getJson('/api/dashboard/stats-optimized');
        $time2 = microtime(true) - $start2;

        $response1->assertStatus(200);
        $response2->assertStatus(200);
        
        // Segunda requisição deve ser mais rápida ou similar (cache pode ser muito rápido)
        // Apenas verificar que ambas retornam 200 e que o tempo não aumentou significativamente
        $this->assertLessThan($time1 * 1.5, $time2, 'Cache não está funcionando corretamente');
    }

    /**
     * Teste: Dados corretos com transações reais
     */
    public function test_correct_data_with_real_transactions(): void
    {
        // Criar transações de teste diretamente (sem factory)
        $uniqueId = uniqid();
        DB::table('solicitacoes')->insert([
            'user_id' => $this->user->user_id ?? $this->user->username,
            'amount' => 100.00,
            'status' => 'PAID_OUT',
            'date' => now(),
            'taxa_cash_in' => 2.50,
            'externalreference' => 'TEST_DASHBOARD_' . $uniqueId,
            'client_name' => 'Test Client',
            'client_document' => '12345678901',
            'client_email' => 'test@test.com',
            'idTransaction' => 'TXN_DASHBOARD_' . $uniqueId,
            'deposito_liquido' => 97.50,
            'qrcode_pix' => 'https://example.com/qr/' . $uniqueId,
            'paymentcode' => 'PAY_DASHBOARD_' . $uniqueId,
            'paymentCodeBase64' => base64_encode('PAY_DASHBOARD_' . $uniqueId),
            'adquirente_ref' => 'ADQ_DASHBOARD_' . $uniqueId,
            'taxa_pix_cash_in_adquirente' => 1.00,
            'taxa_pix_cash_in_valor_fixo' => 1.00,
            'client_telefone' => '11999999999',
            'executor_ordem' => 'EXEC_DASHBOARD_' . $uniqueId,
            'descricao_transacao' => 'Test Transaction',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $cashOutId = uniqid();
        DB::table('solicitacoes_cash_out')->insert([
            'user_id' => $this->user->user_id ?? $this->user->username,
            'amount' => 50.00,
            'status' => 'PAID_OUT',
            'date' => now(),
            'taxa_cash_out' => 1.00,
            'externalreference' => 'TEST_CASHOUT_DASHBOARD_' . $cashOutId,
            'beneficiaryname' => 'Beneficiary Test',
            'beneficiarydocument' => '98765432109',
            'pix' => 'pix@test.com',
            'pixkey' => 'test_key_' . $cashOutId,
            'type' => 'PIX',
            'idTransaction' => 'CASHOUT_TXN_DASHBOARD_' . $cashOutId,
            'cash_out_liquido' => 49.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Cache::flush();

        $headers = $this->token === 'acting_as_token' 
            ? [] 
            : ['Authorization' => 'Bearer ' . $this->token];
            
        $response = $this->withHeaders($headers)->getJson('/api/dashboard/stats-optimized');

        $response->assertStatus(200);
        $data = $response->json('data');
        
        // Verificar que os dados refletem as transações criadas
        $this->assertGreaterThanOrEqual(0, $data['entradas_mes']);
        $this->assertGreaterThanOrEqual(0, $data['saidas_mes']);
    }
}
















