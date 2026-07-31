<?php

namespace Tests\Feature\Api;

use App\Constants\UserPermission;
use App\Constants\UserStatus;
use App\Models\Solicitacoes;
use App\Models\User;
use App\Models\UsersKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Número de pedido do integrador (external_reference):
 *  - consulta por referência é escopada ao dono (não vaza transação de outro cliente);
 *  - sem token/secret válidos, consulta por referência é negada (rota é pública);
 *  - (user_id, client_reference) é único → idempotência garantida no banco.
 */
class DepositExternalReferenceTest extends TestCase
{
    use RefreshDatabase;

    private User $userA;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->userA = User::factory()->create([
            'username' => 'lojaA', 'user_id' => 'lojaA',
            'status' => UserStatus::ACTIVE, 'banido' => 0, 'permission' => UserPermission::CLIENT,
        ]);
        UsersKey::factory()->create(['user_id' => 'lojaA', 'token' => 'tokenA', 'secret' => 'secretA']);

        User::factory()->create([
            'username' => 'lojaB', 'user_id' => 'lojaB',
            'status' => UserStatus::ACTIVE, 'banido' => 0, 'permission' => UserPermission::CLIENT,
        ]);
        UsersKey::factory()->create(['user_id' => 'lojaB', 'token' => 'tokenB', 'secret' => 'secretB']);

        Solicitacoes::factory()->pending()->create([
            'user_id' => 'lojaA',
            'client_reference' => 'pedido-123',
            'amount' => 10.00,
        ]);
    }

    /** @test */
    public function dono_consulta_o_proprio_deposito_pela_referencia(): void
    {
        $r = $this->postJson('/api/status', ['external_reference' => 'pedido-123'], [
            'api-token' => 'tokenA', 'api-secret' => 'secretA',
        ]);

        $r->assertStatus(200);
        $this->assertNotSame('NOT_FOUND', $r->json('status'));
    }

    /** @test */
    public function outro_cliente_nao_ve_a_referencia_de_terceiro(): void
    {
        // lojaB usa a MESMA string de referência, mas é escopado ao próprio user → não encontra.
        $r = $this->postJson('/api/status', ['external_reference' => 'pedido-123'], [
            'api-token' => 'tokenB', 'api-secret' => 'secretB',
        ]);

        $r->assertStatus(200);
        $this->assertSame('NOT_FOUND', $r->json('status'));
    }

    /** @test */
    public function consulta_por_referencia_sem_autenticacao_e_negada(): void
    {
        $r = $this->postJson('/api/status', ['external_reference' => 'pedido-123']);

        $r->assertStatus(401);
    }

    /** @test */
    public function referencia_duplicada_do_mesmo_cliente_e_bloqueada_no_banco(): void
    {
        // Garantia de idempotência: a constraint única (user_id, client_reference) impede duplicar.
        $this->expectException(\Illuminate\Database\QueryException::class);

        Solicitacoes::factory()->pending()->create([
            'user_id' => 'lojaA',
            'client_reference' => 'pedido-123', // já existe no setUp
            'amount' => 10.00,
        ]);
    }

    /** @test */
    public function mesma_referencia_e_permitida_para_clientes_diferentes(): void
    {
        // Escopo por usuário: "pedido-123" da lojaB não colide com o da lojaA.
        $dep = Solicitacoes::factory()->pending()->create([
            'user_id' => 'lojaB',
            'client_reference' => 'pedido-123',
            'amount' => 10.00,
        ]);

        $this->assertDatabaseHas('solicitacoes', ['id' => $dep->id, 'client_reference' => 'pedido-123']);
    }
}
