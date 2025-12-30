<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Models\User;
use App\Models\Nivel;
use App\Models\Solicitacoes;
use App\Services\GamificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Helpers\AuthTestHelper;

/**
 * Testes Unitários - GamificationService
 * 
 * Cobre:
 * - getNiveis (obter níveis com cache)
 * - meuNivel (calcular nível atual)
 * - calculateNextGoal (calcular próxima meta)
 * - Cache de níveis
 * - Cálculo de depósitos
 */
class GamificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private GamificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->service = new GamificationService();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    /**
     * Helper para criar depósito de teste
     */
    private function createDeposito(User $user, array $attributes = []): Solicitacoes
    {
        $defaults = [
            'user_id' => $user->user_id ?? $user->username,
            'idTransaction' => 'TXN' . uniqid(),
            'externalreference' => 'EXT' . uniqid(),
            'amount' => 100.00,
            'deposito_liquido' => 97.50,
            'taxa_cash_in' => 2.50,
            'status' => 'PAID_OUT',
            'date' => now(),
            'descricao_transacao' => 'Depósito teste',
            'client_name' => 'Cliente Teste',
            'client_document' => '12345678900',
            'client_email' => 'cliente@test.com',
            'client_telefone' => '11999999999',
            'qrcode_pix' => 'https://example.com/qr',
            'paymentcode' => 'PAY' . uniqid(),
            'paymentCodeBase64' => base64_encode('PAY' . uniqid()),
            'adquirente_ref' => 'Banco Test',
            'taxa_pix_cash_in_adquirente' => 1.0,
            'taxa_pix_cash_in_valor_fixo' => 0.5,
            'executor_ordem' => 'test',
        ];

        return Solicitacoes::create(array_merge($defaults, $attributes));
    }

    public function test_should_get_niveis_with_cache()
    {
        // Criar níveis de teste
        Nivel::create([
            'nome' => 'Bronze',
            'minimo' => 0,
            'maximo' => 100000,
            'cor' => '#CD7F32',
            'icone' => 'bronze.png',
        ]);

        Nivel::create([
            'nome' => 'Prata',
            'minimo' => 100000,
            'maximo' => 500000,
            'cor' => '#C0C0C0',
            'icone' => 'prata.png',
        ]);

        Cache::forget('gamification:niveis:all');

        // Primeira chamada - deve buscar do banco
        $niveis1 = $this->service->getNiveis();
        $this->assertCount(2, $niveis1);

        // Segunda chamada - deve usar cache
        $niveis2 = $this->service->getNiveis();
        $this->assertCount(2, $niveis2);
        $this->assertEquals($niveis1->first()->id, $niveis2->first()->id);
    }

    public function test_should_get_niveis_ordered_by_minimo()
    {
        // Criar níveis fora de ordem
        Nivel::create([
            'nome' => 'Ouro',
            'minimo' => 500000,
            'maximo' => 1000000,
            'cor' => '#FFD700',
            'icone' => 'ouro.png',
        ]);

        Nivel::create([
            'nome' => 'Bronze',
            'minimo' => 0,
            'maximo' => 100000,
            'cor' => '#CD7F32',
            'icone' => 'bronze.png',
        ]);

        Nivel::create([
            'nome' => 'Prata',
            'minimo' => 100000,
            'maximo' => 500000,
            'cor' => '#C0C0C0',
            'icone' => 'prata.png',
        ]);

        Cache::forget('gamification:niveis:all');

        $niveis = $this->service->getNiveis();

        $this->assertCount(3, $niveis);
        $this->assertEquals('Bronze', $niveis->first()->nome);
        $this->assertEquals('Ouro', $niveis->last()->nome);
    }

    public function test_should_calculate_level_for_user_with_deposits()
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'testuser_' . uniqid(),
            'email' => 'testuser_' . uniqid() . '@example.com',
        ]);

        // Criar níveis
        $bronze = Nivel::create([
            'nome' => 'Bronze',
            'minimo' => 0,
            'maximo' => 100000,
            'cor' => '#CD7F32',
            'icone' => 'bronze.png',
        ]);

        $prata = Nivel::create([
            'nome' => 'Prata',
            'minimo' => 100000,
            'maximo' => 500000,
            'cor' => '#C0C0C0',
            'icone' => 'prata.png',
        ]);

        Cache::forget('gamification:niveis:all');

        // Criar depósitos pagos
        $this->createDeposito($user, ['amount' => 50000]);

        $result = $this->service->meuNivel($user);

        $this->assertEquals(50000, $result['total_depositos']);
        $this->assertNotNull($result['nivel_atual']);
        $this->assertEquals('Bronze', $result['nivel_atual']->nome);
        $this->assertNotNull($result['proximo_nivel']);
        $this->assertEquals('Prata', $result['proximo_nivel']->nome);
    }

    public function test_should_calculate_level_for_user_without_deposits()
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'testuser_' . uniqid(),
            'email' => 'testuser_' . uniqid() . '@example.com',
        ]);

        // Criar níveis
        $bronze = Nivel::create([
            'nome' => 'Bronze',
            'minimo' => 0,
            'maximo' => 100000,
            'cor' => '#CD7F32',
            'icone' => 'bronze.png',
        ]);

        Cache::forget('gamification:niveis:all');

        $result = $this->service->meuNivel($user);

        $this->assertEquals(0, $result['total_depositos']);
        $this->assertNotNull($result['nivel_atual']);
        $this->assertEquals('Bronze', $result['nivel_atual']->nome);
    }

    public function test_should_calculate_level_for_user_at_max_level()
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'testuser_' . uniqid(),
            'email' => 'testuser_' . uniqid() . '@example.com',
        ]);

        // Criar níveis
        $bronze = Nivel::create([
            'nome' => 'Bronze',
            'minimo' => 0,
            'maximo' => 100000,
            'cor' => '#CD7F32',
            'icone' => 'bronze.png',
        ]);

        Cache::forget('gamification:niveis:all');

        // Criar depósito que ultrapassa o máximo do Bronze
        $this->createDeposito($user, ['amount' => 150000]);

        $result = $this->service->meuNivel($user);

        $this->assertEquals(150000, $result['total_depositos']);
        // Deve ficar no último nível disponível
        $this->assertNotNull($result['nivel_atual']);
        $this->assertEquals('Bronze', $result['nivel_atual']->nome);
    }

    public function test_should_calculate_level_for_user_in_middle_level()
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'testuser_' . uniqid(),
            'email' => 'testuser_' . uniqid() . '@example.com',
        ]);

        // Criar níveis
        Nivel::create([
            'nome' => 'Bronze',
            'minimo' => 0,
            'maximo' => 100000,
            'cor' => '#CD7F32',
            'icone' => 'bronze.png',
        ]);

        Nivel::create([
            'nome' => 'Prata',
            'minimo' => 100000,
            'maximo' => 500000,
            'cor' => '#C0C0C0',
            'icone' => 'prata.png',
        ]);

        Cache::forget('gamification:niveis:all');

        // Criar depósito no meio do nível Prata
        $this->createDeposito($user, ['amount' => 300000]);

        $result = $this->service->meuNivel($user);

        $this->assertEquals(300000, $result['total_depositos']);
        $this->assertNotNull($result['nivel_atual']);
        $this->assertEquals('Prata', $result['nivel_atual']->nome);
    }

    public function test_should_only_count_paid_deposits()
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'testuser_' . uniqid(),
            'email' => 'testuser_' . uniqid() . '@example.com',
        ]);

        // Criar níveis
        Nivel::create([
            'nome' => 'Bronze',
            'minimo' => 0,
            'maximo' => 100000,
            'cor' => '#CD7F32',
            'icone' => 'bronze.png',
        ]);

        Cache::forget('gamification:niveis:all');

        // Criar depósito pago
        $this->createDeposito($user, ['amount' => 50000, 'status' => 'PAID_OUT']);

        // Criar depósito pendente (não deve contar)
        $this->createDeposito($user, ['amount' => 50000, 'status' => 'PENDING']);

        $result = $this->service->meuNivel($user);

        // Deve contar apenas o depósito pago
        $this->assertEquals(50000, $result['total_depositos']);
    }

    public function test_should_invalidate_cache_niveis()
    {
        Nivel::create([
            'nome' => 'Bronze',
            'minimo' => 0,
            'maximo' => 100000,
            'cor' => '#CD7F32',
            'icone' => 'bronze.png',
        ]);

        Cache::forget('gamification:niveis:all');

        // Criar cache
        $this->service->getNiveis();

        // Invalidar cache
        $this->service->invalidateCacheNiveis();

        // Cache deve estar vazio
        $cached = Cache::get('gamification:niveis:all');
        $this->assertNull($cached);
    }

    public function test_should_calculate_next_goal_when_not_at_max()
    {
        $bronze = (object)[
            'nome' => 'Bronze',
            'minimo' => 0,
            'maximo' => 100000,
        ];

        $prata = (object)[
            'nome' => 'Prata',
            'minimo' => 100000,
            'maximo' => 500000,
        ];

        $totalDeposited = 50000;

        $goal = $this->service->calculateNextGoal($bronze, $prata, $totalDeposited);

        $this->assertStringContainsString('R$', $goal);
        $this->assertStringContainsString('50.000', $goal);
    }

    public function test_should_calculate_next_goal_when_at_max()
    {
        $bronze = (object)[
            'nome' => 'Bronze',
            'minimo' => 0,
            'maximo' => 100000,
        ];

        $prata = (object)[
            'nome' => 'Prata',
            'minimo' => 100000,
            'maximo' => 500000,
        ];

        $totalDeposited = 100000;

        $goal = $this->service->calculateNextGoal($bronze, $prata, $totalDeposited);

        // Deve mostrar meta do próximo nível
        $this->assertStringContainsString('R$', $goal);
    }

    public function test_should_calculate_next_goal_when_no_next_level()
    {
        $diamante = (object)[
            'nome' => 'Diamante',
            'minimo' => 5000000,
            'maximo' => 10000000,
        ];

        $totalDeposited = 10000000;

        $goal = $this->service->calculateNextGoal($diamante, null, $totalDeposited);

        $this->assertEquals('Concluído!', $goal);
    }

    public function test_should_calculate_next_goal_when_no_current_level()
    {
        $goal = $this->service->calculateNextGoal(null, null, 0);

        $this->assertEquals('Comece depositando!', $goal);
    }

    public function test_should_return_empty_array_when_no_levels()
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'testuser_' . uniqid(),
            'email' => 'testuser_' . uniqid() . '@example.com',
        ]);

        Cache::forget('gamification:niveis:all');

        $result = $this->service->meuNivel($user);

        $this->assertEquals(0, $result['total_depositos']);
        $this->assertNull($result['nivel_atual']);
        $this->assertNull($result['proximo_nivel']);
    }
}

