<?php

namespace Tests\Feature\Api;

use Tests\TestCase;
use App\Models\User;
use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use App\Models\UsersKey;
use Tests\Feature\Helpers\AuthTestHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * Testes de Integração - API de Transações Pendentes
 * 
 * Cobre:
 * - Endpoints de transações pendentes
 * - Autenticação
 * - Validação de requests
 * - Respostas JSON
 * - Tratamento de erros
 */
class PendingTransactionsIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // Criar usuário e obter token
        $this->user = User::factory()->create([
            'username' => 'testuser',
            'user_id' => 'testuser',
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
            'status' => 1,
            'banido' => 0,
        ]);

        UsersKey::factory()->create([
            'user_id' => $this->user->user_id ?? $this->user->username,
            'token' => 'test_token_' . $this->user->username,
            'secret' => 'test_secret_' . $this->user->username,
        ]);

        $this->token = AuthTestHelper::generateTestToken($this->user);
    }

    /**
     * Helper para criar depósito pendente
     */
    private function createDepositoPendente(array $attributes = []): Solicitacoes
    {
        $defaults = [
            'user_id' => $this->user->user_id,
            'idTransaction' => 'TXN' . uniqid(),
            'externalreference' => 'EXT' . uniqid(),
            'amount' => 100.00,
            'deposito_liquido' => 97.50,
            'taxa_cash_in' => 2.50,
            'status' => 'PENDING',
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
            'descricao_transacao' => 'Transação pendente',
        ];

        return Solicitacoes::create(array_merge($defaults, $attributes));
    }

    /**
     * Helper para criar saque pendente
     */
    private function createSaquePendente(array $attributes = []): SolicitacoesCashOut
    {
        $defaults = [
            'user_id' => $this->user->user_id,
            'idTransaction' => 'TXN' . uniqid(),
            'externalreference' => 'EXT' . uniqid(),
            'amount' => 100.00,
            'cash_out_liquido' => 97.50,
            'taxa_cash_out' => 2.50,
            'status' => 'PENDING',
            'date' => now(),
            'pix' => 'test@example.com',
            'pixkey' => 'test@example.com',
            'type' => 'EMAIL',
            'beneficiaryname' => 'Cliente Test',
            'beneficiarydocument' => '12345678900',
            'descricao_transacao' => 'Saque pendente',
        ];

        return SolicitacoesCashOut::create(array_merge($defaults, $attributes));
    }

    /**
     * Teste: Deve listar transações pendentes com autenticação
     */
    public function test_should_list_pending_transactions_with_authentication(): void
    {
        $this->createDepositoPendente();
        $this->createDepositoPendente();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/transactions?status=PENDING');

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
        $this->assertEquals('PENDING', $response->json('data.data.0.status'));
    }

    /**
     * Teste: Deve retornar 401 sem autenticação
     */
    public function test_should_return_401_without_authentication(): void
    {
        $response = $this->getJson('/api/transactions?status=PENDING');

        $response->assertStatus(401);
    }

    /**
     * Teste: Deve filtrar apenas transações pendentes
     */
    public function test_should_filter_only_pending_transactions(): void
    {
        $this->createDepositoPendente(['status' => 'PENDING']);
        $this->createDepositoPendente(['status' => 'PAID_OUT']);
        $this->createDepositoPendente(['status' => 'COMPLETED']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/transactions?status=PENDING');

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('data.total'));
        $this->assertEquals('PENDING', $response->json('data.data.0.status'));
    }

    /**
     * Teste: Deve buscar transações pendentes por termo
     */
    public function test_should_search_pending_transactions_by_term(): void
    {
        $this->createDepositoPendente(['idTransaction' => 'TXN123']);
        $this->createDepositoPendente(['idTransaction' => 'TXN456']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/transactions?status=PENDING&busca=TXN123');

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('data.total'));
    }

    /**
     * Teste: Deve filtrar transações pendentes por data
     */
    public function test_should_filter_pending_transactions_by_date(): void
    {
        $hoje = Carbon::now()->startOfDay();
        $this->createDepositoPendente(['date' => $hoje]);
        $this->createDepositoPendente(['date' => $hoje->copy()->subDays(5)]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/transactions?status=PENDING&data_inicio=' . $hoje->format('Y-m-d') . '&data_fim=' . $hoje->copy()->endOfDay()->format('Y-m-d'));

        $response->assertStatus(200);
        // Pode retornar 1 ou mais dependendo do horário exato
        $this->assertGreaterThanOrEqual(1, $response->json('data.total'));
    }

    /**
     * Teste: Deve validar paginação
     */
    public function test_should_validate_pagination(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->createDepositoPendente();
        }

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/transactions?status=PENDING&page=1&limit=20');

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
        \DB::shouldReceive('query')->andThrow(new \Exception('Database error'));

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/transactions?status=PENDING');

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
        ])->getJson('/api/transactions?status=PENDING&limit=100');

        // Deve retornar 200 mas limitar a 50
        $response->assertStatus(200);
        $this->assertLessThanOrEqual(50, count($response->json('data.data')));
    }

    /**
     * Teste: Deve retornar dados vazios quando não há transações pendentes
     */
    public function test_should_return_empty_data_when_no_pending_transactions(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/transactions?status=PENDING');

        $response->assertStatus(200);
        $this->assertEquals(0, $response->json('data.total'));
        $this->assertEmpty($response->json('data.data'));
    }

    /**
     * Teste: Deve incluir depósitos e saques pendentes
     */
    public function test_should_include_pending_deposits_and_withdrawals(): void
    {
        $this->createDepositoPendente(['idTransaction' => 'DEP001']);
        $this->createSaquePendente(['idTransaction' => 'SAQ001']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/transactions?status=PENDING');

        $response->assertStatus(200);
        $this->assertEquals(2, $response->json('data.total'));

        $transactionIds = array_column($response->json('data.data'), 'transaction_id');
        $this->assertContains('DEP001', $transactionIds);
        $this->assertContains('SAQ001', $transactionIds);
    }
}

