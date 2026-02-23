<?php

namespace Tests\Feature\Api;

use App\Models\App;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use Tests\Feature\Helpers\AuthTestHelper;

/**
 * Testes do GatewaySettingsController (rotas admin/settings)
 *
 * Cobre: GET admin/settings (com auth admin), PUT admin/settings (validação e atualização).
 */
class GatewaySettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    private $adminUser;
    private $clientUser;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->adminUser = AuthTestHelper::createTestUser([
            'username' => 'admin_settings_' . uniqid(),
            'email' => 'admin_settings_' . uniqid() . '@example.com',
            'permission' => 3, // Admin
            'status' => 1,
        ]);
        $this->clientUser = AuthTestHelper::createTestUser([
            'username' => 'client_settings_' . uniqid(),
            'email' => 'client_settings_' . uniqid() . '@example.com',
            'permission' => 1, // Cliente
            'status' => 1,
        ]);
    }

    /** @test */
    public function get_settings_retorna_200_e_dados_com_admin_jwt(): void
    {
        App::create([
            'taxa_fixa_padrao' => 1.00,
            'taxa_fixa_pix' => 1.00,
            'taxa_fixa_padrao_cash_out' => 0,
        ]);

        $token = AuthTestHelper::generateTestToken($this->adminUser);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/settings');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'taxa_fixa_deposito',
                    'taxa_fixa_pix',
                    'taxa_comissao_afiliado_padrao',
                ],
            ]);
    }

    /** @test */
    public function get_settings_retorna_401_sem_token(): void
    {
        $response = $this->getJson('/api/admin/settings');
        $response->assertStatus(401);
    }

    /** @test */
    public function get_settings_retorna_403_com_usuario_cliente(): void
    {
        $token = AuthTestHelper::generateTestToken($this->clientUser);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/settings');

        $response->assertStatus(403);
    }

    /** @test */
    public function update_settings_atualiza_taxas_com_admin_jwt(): void
    {
        App::create([
            'taxa_fixa_padrao' => 1.00,
            'taxa_fixa_pix' => 1.00,
            'taxa_fixa_padrao_cash_out' => 0,
        ]);

        $token = AuthTestHelper::generateTestToken($this->adminUser);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/admin/settings', [
                'taxa_fixa_deposito' => 1.50,
                'taxa_fixa_pix' => 0.90,
                'taxa_comissao_afiliado_padrao' => 0.60,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.taxa_fixa_deposito', 1.50)
            ->assertJsonPath('data.taxa_fixa_pix', 0.90)
            ->assertJsonPath('data.taxa_comissao_afiliado_padrao', 0.60);
    }

    /** @test */
    public function update_settings_rejeita_taxa_negativa(): void
    {
        $token = AuthTestHelper::generateTestToken($this->adminUser);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/admin/settings', [
                'taxa_fixa_deposito' => -1,
                'taxa_fixa_pix' => 1.00,
            ]);

        $response->assertStatus(422);
    }
}
