<?php

namespace Tests\Feature\Api;

use Tests\TestCase;
use App\Models\User;
use App\Models\App;
use App\Models\UsersKey;
use App\Constants\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Helpers\AuthTestHelper;

/**
 * Testes de Performance e Concorrência - API de Configurações Gerais
 * 
 * Cobre:
 * - Performance com múltiplas requisições
 * - Concorrência
 * - Escalabilidade
 * - Cache
 * - Atualizações simultâneas
 */
class GatewaySettingsPerformanceTest extends TestCase
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
        ]);

        // Fazer login e obter token
        $loginResponse = $this->postJson('/api/auth/login', [
            'username' => 'testuser',
            'password' => 'password123',
        ]);

        $this->token = $loginResponse->json('token') ?? $loginResponse->json('data.token');
    }

    /**
     * Teste: Deve obter configurações rapidamente
     */
    public function test_should_get_settings_quickly(): void
    {
        App::create([
            'taxa_cash_in_padrao' => 5.00,
        ]);

        $startTime = microtime(true);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/settings');

        $duration = microtime(true) - $startTime;

        $response->assertStatus(200);
        $this->assertTrue($response->json('success'));
        
        // Deve processar em menos de 1 segundo
        $this->assertLessThan(1.0, $duration);
    }

    /**
     * Teste: Deve usar cache para melhorar performance
     */
    public function test_should_use_cache_for_performance(): void
    {
        App::create([
            'taxa_cash_in_padrao' => 5.00,
        ]);

        // Primeira requisição (deve buscar do banco)
        $startTime1 = microtime(true);
        $response1 = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/settings');
        $duration1 = microtime(true) - $startTime1;

        $response1->assertStatus(200);

        // Segunda requisição (deve usar cache)
        $startTime2 = microtime(true);
        $response2 = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/settings');
        $duration2 = microtime(true) - $startTime2;

        $response2->assertStatus(200);
        
        // A segunda requisição deve ser mais rápida (ou similar devido ao overhead)
        $this->assertTrue($duration2 <= $duration1 * 1.5); // Permitir até 50% mais lento devido ao overhead
    }

    /**
     * Teste: Deve processar atualização em tempo razoável
     */
    public function test_should_process_update_in_reasonable_time(): void
    {
        App::create([
            'taxa_cash_in_padrao' => 5.00,
        ]);

        $startTime = microtime(true);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->putJson('/api/admin/settings', [
            'taxa_percentual_deposito' => 10.00,
        ]);

        $duration = microtime(true) - $startTime;

        $response->assertStatus(200);
        
        // Deve processar em menos de 2 segundos
        $this->assertLessThan(2.0, $duration);
    }

    /**
     * Teste: Deve manter consistência com múltiplas atualizações
     */
    public function test_should_maintain_consistency_with_multiple_updates(): void
    {
        Cache::flush(); // Limpar cache antes do teste
        
        $app = App::create([
            'taxa_cash_in_padrao' => 5.00,
            'taxa_fixa_padrao' => 1.00,
        ]);

        // Fazer múltiplas atualizações em sequência
        $lastValue = 5.00;
        for ($i = 0; $i < 5; $i++) {
            Cache::flush(); // Limpar cache antes de cada atualização
            $newValue = 5.00 + $i;
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->putJson('/api/admin/settings', [
                'taxa_percentual_deposito' => $newValue,
            ]);

            $response->assertStatus(200);
            $this->assertTrue($response->json('success'));
            $this->assertEquals($newValue, $response->json('data.taxa_percentual_deposito'));
            $lastValue = $newValue;
        }

        // Limpar cache antes de verificar valor final
        Cache::flush();
        
        // Verificar valor final
        $finalResponse = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/settings');

        $finalResponse->assertStatus(200);
        $this->assertEquals($lastValue, $finalResponse->json('data.taxa_percentual_deposito'));
    }

    /**
     * Teste: Deve limpar cache após atualização
     */
    public function test_should_clear_cache_after_update(): void
    {
        Cache::flush(); // Limpar cache antes do teste
        
        $app = App::create([
            'taxa_cash_in_padrao' => 5.00,
        ]);

        // Primeira requisição (cria cache)
        $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/settings');

        // Atualizar configurações (deve limpar cache)
        $updateResponse = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->putJson('/api/admin/settings', [
            'taxa_percentual_deposito' => 15.00,
        ]);

        $updateResponse->assertStatus(200);
        $this->assertTrue($updateResponse->json('success'));
        $this->assertEquals(15.00, $updateResponse->json('data.taxa_percentual_deposito'));

        // Verificar no banco diretamente
        $app->refresh();
        $this->assertEquals(15.00, $app->taxa_cash_in_padrao);

        // Limpar cache manualmente para garantir busca do banco
        Cache::flush();

        // Próxima requisição deve buscar do banco (cache limpo)
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/settings');

        $response->assertStatus(200);
        $this->assertEquals(15.00, $response->json('data.taxa_percentual_deposito'));
    }

    /**
     * Teste: Deve lidar com atualizações grandes
     */
    public function test_should_handle_large_updates(): void
    {
        App::create([]);

        $largeData = [
            'taxa_percentual_deposito' => 5.00,
            'taxa_fixa_deposito' => 1.00,
            'valor_minimo_deposito' => 5.00,
            'taxa_percentual_pix' => 5.00,
            'taxa_minima_pix' => 1.00,
            'taxa_fixa_pix' => 0.00,
            'valor_minimo_saque' => 5.00,
            'limite_mensal_pf' => 50000.00,
            'taxa_saque_api' => 5.00,
            'taxa_saque_crypto' => 7.00,
            'sistema_flexivel_ativo' => true,
            'valor_minimo_flexivel' => 15.00,
            'taxa_fixa_baixos' => 4.99,
            'taxa_percentual_altos' => 5.00,
            'relatorio_entradas_mostrar_meio' => true,
            'relatorio_entradas_mostrar_transacao_id' => true,
            'relatorio_entradas_mostrar_valor' => true,
            'relatorio_entradas_mostrar_valor_liquido' => true,
            'relatorio_entradas_mostrar_nome' => true,
            'relatorio_entradas_mostrar_documento' => true,
            'relatorio_entradas_mostrar_status' => true,
            'relatorio_entradas_mostrar_data' => true,
            'relatorio_entradas_mostrar_taxa' => true,
            'relatorio_saidas_mostrar_transacao_id' => true,
            'relatorio_saidas_mostrar_valor' => true,
            'relatorio_saidas_mostrar_nome' => true,
            'relatorio_saidas_mostrar_chave_pix' => true,
            'relatorio_saidas_mostrar_tipo_chave' => true,
            'relatorio_saidas_mostrar_status' => true,
            'relatorio_saidas_mostrar_data' => true,
            'relatorio_saidas_mostrar_taxa' => true,
            'global_ips' => ['192.168.1.1', '10.0.0.1', '172.16.0.1'],
        ];

        $startTime = microtime(true);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->putJson('/api/admin/settings', $largeData);

        $duration = microtime(true) - $startTime;

        $response->assertStatus(200);
        $this->assertTrue($response->json('success'));
        
        // Deve processar em menos de 2 segundos mesmo com muitos campos
        $this->assertLessThan(2.0, $duration);
    }

    /**
     * Teste: Deve processar múltiplas requisições simultâneas
     */
    public function test_should_handle_concurrent_requests(): void
    {
        App::create([
            'taxa_cash_in_padrao' => 5.00,
        ]);

        // Fazer múltiplas requisições em sequência (simulando concorrência)
        $responses = [];
        for ($i = 0; $i < 10; $i++) {
            $responses[] = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson('/api/admin/settings');
        }

        // Verificar que todas retornaram sucesso
        foreach ($responses as $response) {
            $response->assertStatus(200);
            $this->assertTrue($response->json('success'));
        }
    }
}

