<?php

namespace Tests\Feature\Api;

use Tests\TestCase;
use App\Models\Solicitacoes;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Testes de Integração - POST /api/status
 * 
 * Rota crítica: Consultar status de transação (depósito/saque)
 * Middleware: throttle:20,1
 * 
 * Importante: Rota pública (sem autenticação)
 * 
 * Cenários testados:
 * - Consulta por idTransaction válido
 * - Consulta por externalreference válido
 * - Transação não encontrada
 * - Diferentes status (pending, paid, cancelled, etc.)
 */
class TransactionStatusIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Solicitacoes $transaction;

    protected function setUp(): void
    {
        parent::setUp();

        // Criar usuário
        $this->user = User::factory()->create([
            'username' => 'teststatususer',
            'user_id' => 'teststatususer',
        ]);

        // Criar transação de teste
        $this->transaction = Solicitacoes::factory()->create([
            'user_id' => $this->user->user_id,
            'idTransaction' => 'TXN_TEST_STATUS_123',
            'externalreference' => 'EXT_REF_123',
            'amount' => 100.00,
            'status' => 'PAID_OUT',
        ]);
    }

    /** @test */
    public function deve_retornar_status_por_idTransaction(): void
    {
        $response = $this->postJson('/api/status', [
            'idTransaction' => 'TXN_TEST_STATUS_123',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'PAID_OUT',
            ]);
    }

    /** @test */
    public function deve_retornar_status_por_externalreference(): void
    {
        $response = $this->postJson('/api/status', [
            'idTransaction' => 'EXT_REF_123', // A rota aceita externalreference também
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'PAID_OUT',
            ]);
    }

    /** @test */
    public function deve_retornar_NOT_FOUND_quando_transacao_nao_existe(): void
    {
        $response = $this->postJson('/api/status', [
            'idTransaction' => 'TXN_NAO_EXISTE_999',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'NOT_FOUND',
            ]);
    }

    /** @test */
    public function deve_retornar_status_correto_para_transacao_pendente(): void
    {
        $pendingTransaction = Solicitacoes::factory()->create([
            'user_id' => $this->user->user_id,
            'idTransaction' => 'TXN_PENDING_456',
            'status' => 'pending',
        ]);

        $response = $this->postJson('/api/status', [
            'idTransaction' => 'TXN_PENDING_456',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'pending',
            ]);
    }

    /** @test */
    public function deve_retornar_status_para_transacao_cancelada(): void
    {
        $cancelledTransaction = Solicitacoes::factory()->create([
            'user_id' => $this->user->user_id,
            'idTransaction' => 'TXN_CANCELLED_789',
            'status' => 'cancelled',
        ]);

        $response = $this->postJson('/api/status', [
            'idTransaction' => 'TXN_CANCELLED_789',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'cancelled',
            ]);
    }

    /** @test */
    public function deve_funcionar_sem_autenticacao(): void
    {
        // Esta rota é pública - não requer token JWT ou token+secret
        $response = $this->postJson('/api/status', [
            'idTransaction' => 'TXN_TEST_STATUS_123',
        ]);

        $response->assertStatus(200);
    }

    /** @test */
    public function deve_buscar_por_idTransaction_ou_externalreference_indistintamente(): void
    {
        // Criar transação com ids únicos
        $txn = Solicitacoes::factory()->create([
            'user_id' => $this->user->user_id,
            'idTransaction' => 'ID_UNICO_999',
            'externalreference' => 'EXT_UNICO_888',
            'status' => 'PAID_OUT',
        ]);

        // Buscar por idTransaction
        $response1 = $this->postJson('/api/status', [
            'idTransaction' => 'ID_UNICO_999',
        ]);

        $response1->assertStatus(200)
            ->assertJson(['status' => 'PAID_OUT']);

        // Buscar por externalreference
        $response2 = $this->postJson('/api/status', [
            'idTransaction' => 'EXT_UNICO_888',
        ]);

        $response2->assertStatus(200)
            ->assertJson(['status' => 'PAID_OUT']);
    }
}
