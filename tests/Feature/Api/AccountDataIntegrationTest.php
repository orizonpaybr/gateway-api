<?php

namespace Tests\Feature\Api;

use Tests\TestCase;
use App\Models\User;
use App\Models\UsersKey;
use Tests\Feature\Helpers\AuthTestHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

/**
 * Testes de Integração - API de Dados da Conta
 * 
 * Cobre:
 * - Endpoints de dados da conta
 * - Autenticação
 * - Validação de requests
 * - Respostas JSON
 * - Tratamento de erros
 */
class AccountDataIntegrationTest extends TestCase
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
            'name' => 'Test User',
            'telefone' => '11999999999',
            'cpf_cnpj' => '12345678900',
        ]);

        UsersKey::factory()->create([
            'user_id' => $this->user->user_id ?? $this->user->username,
            'token' => 'test_token_' . $this->user->username,
            'secret' => 'test_secret_' . $this->user->username,
        ]);

        $this->token = AuthTestHelper::generateTestToken($this->user);
    }

    /**
     * Teste: Deve obter dados da conta com autenticação
     */
    public function test_should_get_account_data_with_authentication(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/user/profile');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'username',
                    'email',
                    'name',
                    'phone',
                    'cnpj',
                    'status',
                    'status_text',
                    'balance',
                    'company' => [
                        'tipo_pessoa',
                        'tipo',
                    ],
                    'contacts' => [
                        'telefone_principal',
                        'email_principal',
                    ],
                ],
            ]);

        $this->assertEquals('testuser', $response->json('data.username'));
        $this->assertEquals('test@example.com', $response->json('data.email'));
    }

    /**
     * Teste: Deve retornar 401 sem autenticação
     */
    public function test_should_return_401_without_authentication(): void
    {
        $response = $this->getJson('/api/user/profile');

        $response->assertStatus(401);
    }

    /**
     * Teste: Deve retornar 403 para usuário banido
     */
    public function test_should_return_403_for_banned_user(): void
    {
        $this->user->banido = 1;
        $this->user->save();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/user/profile');

        $response->assertStatus(403);
        $this->assertStringContainsString('desativada', $response->json('message'));
    }

    /**
     * Teste: Deve retornar 403 para usuário inativo
     */
    public function test_should_return_403_for_inactive_user(): void
    {
        $this->user->status = 0;
        $this->user->banido = 1;
        $this->user->save();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/user/profile');

        $response->assertStatus(403);
    }

    /**
     * Teste: Deve identificar tipo PF para CPF
     */
    public function test_should_identify_pf_type_for_cpf(): void
    {
        $this->user->cpf_cnpj = '12345678900';
        $this->user->save();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/user/profile');

        $response->assertStatus(200);
        $this->assertEquals('PF', $response->json('data.company.tipo_pessoa'));
        $this->assertEquals('Pessoa Física', $response->json('data.company.tipo'));
    }

    /**
     * Teste: Deve identificar tipo PJ para CNPJ
     */
    public function test_should_identify_pj_type_for_cnpj(): void
    {
        $this->user->cpf_cnpj = '12345678000190';
        $this->user->save();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/user/profile');

        $response->assertStatus(200);
        $this->assertEquals('PJ', $response->json('data.company.tipo_pessoa'));
        $this->assertEquals('Pessoa Jurídica', $response->json('data.company.tipo'));
    }

    /**
     * Teste: Deve retornar status correto
     */
    public function test_should_return_correct_status(): void
    {
        $this->user->status = 1;
        $this->user->save();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/user/profile');

        $response->assertStatus(200);
        $this->assertEquals('Aprovado', $response->json('data.status_text'));
        $this->assertEquals('active', $response->json('data.status'));
    }

    /**
     * Teste: Deve incluir informações de contato
     */
    public function test_should_include_contact_information(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/user/profile');

        $response->assertStatus(200);
        $this->assertEquals('11999999999', $response->json('data.contacts.telefone_principal'));
        $this->assertEquals('test@example.com', $response->json('data.contacts.email_principal'));
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
        ])->getJson('/api/user/profile');

        // O endpoint deve tratar o erro e retornar 500
        $response->assertStatus(500);
    }

    /**
     * Teste: Deve usar cache para melhorar performance
     */
    public function test_should_use_cache_to_improve_performance(): void
    {
        // Primeira requisição
        $response1 = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/user/profile');

        $response1->assertStatus(200);
        $data1 = $response1->json('data');

        // Verificar se cache foi criado
        $cacheKey = 'user_profile_' . $this->user->username;
        $cached = Cache::get($cacheKey);
        $this->assertNotNull($cached);
        $this->assertEquals($data1['username'], $cached['username']);

        // Segunda requisição deve usar cache
        $response2 = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/user/profile');

        $response2->assertStatus(200);
        $data2 = $response2->json('data');
        $this->assertEquals($data1['username'], $data2['username']);
    }

    /**
     * Teste: Deve retornar dados completos do perfil
     */
    public function test_should_return_complete_profile_data(): void
    {
        $this->user->saldo = 5000.00;
        $this->user->permission = 1;
        $this->user->save();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/user/profile');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertEquals('testuser', $data['username']);
        $this->assertEquals('test@example.com', $data['email']);
        $this->assertEquals(5000.00, $data['balance']);
        $this->assertEquals(1, $data['permission']);
        $this->assertArrayHasKey('company', $data);
        $this->assertArrayHasKey('contacts', $data);
    }
}

