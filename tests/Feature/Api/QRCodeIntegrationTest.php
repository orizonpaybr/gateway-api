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
 * Testes de Integração - API de QR Codes
 * 
 * Cobre:
 * - Endpoints de QR Codes
 * - Autenticação
 * - Validação de requests
 * - Respostas JSON
 * - Tratamento de erros
 */
class QRCodeIntegrationTest extends TestCase
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
     * Helper para criar depósito (QR Code)
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
            'descricao_transacao' => 'QR Code de teste',
        ];

        return Solicitacoes::create(array_merge($defaults, $attributes));
    }

    /**
     * Helper para criar saque (QR Code)
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
            'descricao_transacao' => 'QR Code de saque',
        ];

        return SolicitacoesCashOut::create(array_merge($defaults, $attributes));
    }

    /**
     * Teste: Deve listar QR Codes com autenticação
     */
    public function test_should_list_qrcodes_with_authentication(): void
    {
        $this->createDeposito();
        $this->createDeposito();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/qrcodes');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'data' => [
                        '*' => [
                            'id',
                            'nome',
                            'descricao',
                            'valor',
                            'status',
                            'transaction_id',
                            'data_criacao',
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
        $response = $this->getJson('/api/qrcodes');

        $response->assertStatus(401);
    }

    /**
     * Teste: Deve filtrar QR Codes por status
     */
    public function test_should_filter_qrcodes_by_status(): void
    {
        $this->createDeposito(['status' => 'PAID_OUT']);
        $this->createDeposito(['status' => 'PENDING']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/qrcodes?status=PAID_OUT');

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('data.total'));
    }

    /**
     * Teste: Deve buscar QR Codes por termo
     */
    public function test_should_search_qrcodes_by_term(): void
    {
        $this->createDeposito(['idTransaction' => 'TXN123']);
        $this->createDeposito(['idTransaction' => 'TXN456']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/qrcodes?busca=TXN123');

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('data.total'));
    }

    /**
     * Teste: Deve filtrar QR Codes por data
     */
    public function test_should_filter_qrcodes_by_date(): void
    {
        $hoje = Carbon::now();
        $this->createDeposito(['date' => $hoje]);
        $this->createDeposito(['date' => $hoje->copy()->subDays(5)]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/qrcodes?data_inicio=' . $hoje->format('Y-m-d') . '&data_fim=' . $hoje->format('Y-m-d'));

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('data.total'));
    }

    /**
     * Teste: Deve validar paginação
     */
    public function test_should_validate_pagination(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->createDeposito();
        }

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/qrcodes?page=1&limit=20');

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
        \DB::shouldReceive('table')->andThrow(new \Exception('Database error'));

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/qrcodes');

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
        ])->getJson('/api/qrcodes?limit=150');

        // Deve retornar 200 ou limitar a 100
        $this->assertContains($response->status(), [200, 422]);
        if ($response->status() === 200) {
            $this->assertLessThanOrEqual(100, count($response->json('data.data')));
        }
    }

    /**
     * Teste: Deve retornar dados vazios quando não há QR Codes
     */
    public function test_should_return_empty_data_when_no_qrcodes(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/qrcodes');

        $response->assertStatus(200);
        $this->assertEquals(0, $response->json('data.total'));
        $this->assertEmpty($response->json('data.data'));
    }

    /**
     * Teste: Deve incluir QR Codes de depósitos e saques
     */
    public function test_should_include_deposits_and_withdrawals(): void
    {
        $this->createDeposito(['idTransaction' => 'DEP001']);
        $this->createSaque(['idTransaction' => 'SAQ001']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/qrcodes');

        $response->assertStatus(200);
        $this->assertEquals(2, $response->json('data.total'));

        $transactionIds = array_column($response->json('data.data'), 'transaction_id');
        $this->assertContains('DEP001', $transactionIds);
        $this->assertContains('SAQ001', $transactionIds);
    }
}

