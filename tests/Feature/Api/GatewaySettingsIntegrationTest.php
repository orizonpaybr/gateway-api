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
 * Testes de Integração - API de Configurações Gerais do Gateway
 * 
 * Cobre:
 * - Endpoint GET /api/admin/settings
 * - Endpoint PUT /api/admin/settings
 * - Autenticação
 * - Validação de requests
 * - Respostas JSON
 * - Tratamento de erros
 * - Mapeamento de campos
 */
class GatewaySettingsIntegrationTest extends TestCase
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
     * Teste: Deve obter configurações com sucesso
     */
    public function test_should_get_settings(): void
    {
        // Criar configurações
        App::create([
            'taxa_cash_in_padrao' => 5.00,
            'taxa_fixa_padrao' => 1.00,
            'deposito_minimo' => 5.00,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/settings');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'taxa_percentual_deposito',
                    'taxa_fixa_deposito',
                    'valor_minimo_deposito',
                    'taxa_percentual_pix',
                    'taxa_minima_pix',
                    'taxa_fixa_pix',
                    'valor_minimo_saque',
                    'limite_mensal_pf',
                    'taxa_saque_api',
                    'taxa_saque_crypto',
                    'sistema_flexivel_ativo',
                    'valor_minimo_flexivel',
                    'taxa_fixa_baixos',
                    'taxa_percentual_altos',
                    'relatorio_entradas_mostrar_meio',
                    'relatorio_entradas_mostrar_transacao_id',
                    'relatorio_entradas_mostrar_valor',
                    'relatorio_entradas_mostrar_valor_liquido',
                    'relatorio_entradas_mostrar_nome',
                    'relatorio_entradas_mostrar_documento',
                    'relatorio_entradas_mostrar_status',
                    'relatorio_entradas_mostrar_data',
                    'relatorio_entradas_mostrar_taxa',
                    'relatorio_saidas_mostrar_transacao_id',
                    'relatorio_saidas_mostrar_valor',
                    'relatorio_saidas_mostrar_nome',
                    'relatorio_saidas_mostrar_chave_pix',
                    'relatorio_saidas_mostrar_tipo_chave',
                    'relatorio_saidas_mostrar_status',
                    'relatorio_saidas_mostrar_data',
                    'relatorio_saidas_mostrar_taxa',
                    'global_ips',
                ],
            ]);

        $this->assertTrue($response->json('success'));
        $this->assertEquals(5.00, $response->json('data.taxa_percentual_deposito'));
    }

    /**
     * Teste: Deve criar configurações padrão se não existir
     */
    public function test_should_create_default_settings_if_not_exists(): void
    {
        // Não criar configurações manualmente

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/settings');

        $response->assertStatus(200);
        $this->assertTrue($response->json('success'));
        $this->assertArrayHasKey('data', $response->json());
    }

    /**
     * Teste: Deve atualizar configurações com sucesso
     */
    public function test_should_update_settings(): void
    {
        App::create([
            'taxa_cash_in_padrao' => 5.00,
            'taxa_fixa_padrao' => 1.00,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->putJson('/api/admin/settings', [
            'taxa_percentual_deposito' => 10.00,
            'taxa_fixa_deposito' => 2.00,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data',
            ]);

        $this->assertTrue($response->json('success'));
        $this->assertEquals(10.00, $response->json('data.taxa_percentual_deposito'));
        $this->assertEquals(2.00, $response->json('data.taxa_fixa_deposito'));
    }

    /**
     * Teste: Deve validar campos obrigatórios
     */
    public function test_should_validate_fields(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->putJson('/api/admin/settings', [
            'taxa_percentual_deposito' => 150.00, // Mais que o máximo (100)
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('errors', $response->json());
    }

    /**
     * Teste: Deve validar taxa percentual máxima
     */
    public function test_should_validate_percentage_maximum(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->putJson('/api/admin/settings', [
            'taxa_percentual_deposito' => 150.00,
        ]);

        $response->assertStatus(422);
    }

    /**
     * Teste: Deve validar valores mínimos
     */
    public function test_should_validate_minimum_values(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->putJson('/api/admin/settings', [
            'taxa_percentual_deposito' => -1.00,
        ]);

        $response->assertStatus(422);
    }

    /**
     * Teste: Deve atualizar sistema de taxas flexível
     */
    public function test_should_update_flexible_tax_system(): void
    {
        App::create([
            'taxa_flexivel_ativa' => false,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->putJson('/api/admin/settings', [
            'sistema_flexivel_ativo' => true,
            'valor_minimo_flexivel' => 20.00,
            'taxa_fixa_baixos' => 5.00,
            'taxa_percentual_altos' => 6.00,
        ]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('success'));
        $this->assertTrue($response->json('data.sistema_flexivel_ativo'));
    }

    /**
     * Teste: Deve atualizar personalização de relatórios
     */
    public function test_should_update_report_customization(): void
    {
        App::create([
            'relatorio_entradas_mostrar_meio' => true,
            'relatorio_entradas_mostrar_valor' => true,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->putJson('/api/admin/settings', [
            'relatorio_entradas_mostrar_meio' => false,
            'relatorio_entradas_mostrar_valor' => false,
        ]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('success'));
        $this->assertFalse($response->json('data.relatorio_entradas_mostrar_meio'));
        $this->assertFalse($response->json('data.relatorio_entradas_mostrar_valor'));
    }

    /**
     * Teste: Deve atualizar IPs globais
     */
    public function test_should_update_global_ips(): void
    {
        App::create([
            'global_ips' => [],
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->putJson('/api/admin/settings', [
            'global_ips' => ['192.168.1.1', '10.0.0.1'],
        ]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('success'));
        $this->assertIsArray($response->json('data.global_ips'));
        $this->assertContains('192.168.1.1', $response->json('data.global_ips'));
    }

    /**
     * Teste: Deve retornar erro 401 sem autenticação
     */
    public function test_should_require_authentication(): void
    {
        $response = $this->getJson('/api/admin/settings');

        $response->assertStatus(401);
    }

    /**
     * Teste: Deve retornar erro 403 para não-admin
     */
    public function test_should_require_admin_permission(): void
    {
        $nonAdmin = AuthTestHelper::createTestUser([
            'username' => 'nonadmin_' . uniqid(),
            'email' => 'nonadmin_' . uniqid() . '@example.com',
            'permission' => UserPermission::CLIENT,
        ]);

        UsersKey::factory()->create([
            'user_id' => $nonAdmin->user_id ?? $nonAdmin->username,
            'token' => 'test_token_' . $nonAdmin->username,
        ]);

        $loginResponse = $this->postJson('/api/auth/login', [
            'username' => $nonAdmin->username,
            'password' => 'password123',
        ]);

        $nonAdminToken = $loginResponse->json('token') ?? $loginResponse->json('data.token');

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $nonAdminToken,
        ])->getJson('/api/admin/settings');

        // Deve retornar 403 ou 401 dependendo do middleware
        $this->assertContains($response->status(), [401, 403]);
    }

    /**
     * Teste: Deve validar formato de IPs
     */
    public function test_should_validate_ip_format(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->putJson('/api/admin/settings', [
            'global_ips' => ['invalid-ip', '999.999.999.999'],
        ]);

        $response->assertStatus(422);
    }
}








