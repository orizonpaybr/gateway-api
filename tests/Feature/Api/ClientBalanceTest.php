<?php

namespace Tests\Feature\Api;

use App\Constants\UserPermission;
use App\Constants\UserStatus;
use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use App\Models\User;
use App\Models\UsersKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Testes do endpoint público de consulta de saldo (GET /api/wallet/balance).
 *
 * Cenários:
 * - Autenticação via token + secret
 * - Cálculo de saldo disponível e movimentação do mês
 * - Bloqueio sem credenciais
 */
class ClientBalanceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->user = User::factory()->create([
            'username' => 'balanceuser',
            'user_id' => 'balanceuser',
            'status' => UserStatus::ACTIVE,
            'banido' => 0,
            'permission' => UserPermission::CLIENT,
            'saldo' => 290.47,
            'saldo_afiliado' => 9.00,
        ]);

        UsersKey::factory()->create([
            'user_id' => $this->user->user_id,
            'token' => 'balance_token',
            'secret' => 'balance_secret',
        ]);
    }

    private function authHeaders(): array
    {
        return [
            'api-token' => 'balance_token',
            'api-secret' => 'balance_secret',
        ];
    }

    /** @test */
    public function deve_retornar_saldo_disponivel_e_movimentacao_do_mes(): void
    {
        Solicitacoes::factory()->paidOut()->create([
            'user_id' => $this->user->username,
            'amount' => 2.00,
            'date' => now(),
        ]);

        SolicitacoesCashOut::factory()->completed()->create([
            'user_id' => $this->user->username,
            'amount' => 1.00,
            'date' => now(),
        ]);

        $response = $this->getJson('/api/wallet/balance', $this->authHeaders());

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'moeda' => 'BRL',
                    'saldo_disponivel' => 299.47,
                    'entradas_mes' => 2.00,
                    'saidas_mes' => 1.00,
                    'fluxo_liquido_mes' => 1.00,
                ],
            ])
            ->assertJsonStructure([
                'status',
                'data' => [
                    'moeda',
                    'saldo_disponivel',
                    'entradas_mes',
                    'saidas_mes',
                    'fluxo_liquido_mes',
                    'periodo' => ['inicio', 'fim'],
                    'atualizado_em',
                ],
            ]);
    }

    /** @test */
    public function nao_deve_contar_transacoes_pendentes_nem_de_outros_meses(): void
    {
        Solicitacoes::factory()->pending()->create([
            'user_id' => $this->user->username,
            'amount' => 500.00,
            'date' => now(),
        ]);

        Solicitacoes::factory()->paidOut()->create([
            'user_id' => $this->user->username,
            'amount' => 999.00,
            'date' => now()->subMonthNoOverflow()->startOfMonth(),
        ]);

        $response = $this->getJson('/api/wallet/balance', $this->authHeaders());

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'entradas_mes' => 0.0,
                    'saidas_mes' => 0.0,
                    'fluxo_liquido_mes' => 0.0,
                ],
            ]);
    }

    /** @test */
    public function deve_rejeitar_requisicao_sem_credenciais(): void
    {
        $response = $this->getJson('/api/wallet/balance');

        $response->assertStatus(400)
            ->assertJson([
                'error' => 'Token ou Secret ausentes',
            ]);
    }

    /** @test */
    public function deve_bloquear_ip_apos_tres_tentativas_falhas_consecutivas(): void
    {
        $server = ['REMOTE_ADDR' => '203.0.113.50'];

        for ($i = 0; $i < 3; $i++) {
            $this->withServerVariables($server)
                ->getJson('/api/wallet/balance')
                ->assertStatus(400);
        }

        $this->withServerVariables($server)
            ->getJson('/api/wallet/balance')
            ->assertStatus(429)
            ->assertJson([
                'status' => 'error',
                'message' => 'Muitas tentativas falhas deste IP. Tente novamente mais tarde.',
            ])
            ->assertJsonStructure(['retry_after']);
    }

    /** @test */
    public function deve_zerar_contador_de_falhas_apos_requisicao_bem_sucedida(): void
    {
        $server = ['REMOTE_ADDR' => '203.0.113.51'];

        $this->withServerVariables($server)->getJson('/api/wallet/balance')->assertStatus(400);
        $this->withServerVariables($server)->getJson('/api/wallet/balance')->assertStatus(400);

        $this->withServerVariables($server)
            ->getJson('/api/wallet/balance', $this->authHeaders())
            ->assertStatus(200);

        $this->withServerVariables($server)->getJson('/api/wallet/balance')->assertStatus(400);
    }
}
