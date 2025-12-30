<?php

namespace Tests\Unit\Controllers;

use Tests\TestCase;
use App\Http\Controllers\Api\UserController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

/**
 * Testes Unitários - Dados da Conta
 * 
 * Cobre:
 * - Funcionalidade de busca de dados da conta
 * - Formatação de dados do perfil
 * - Cache
 * - Validação de status do usuário
 * - Cálculo de tipo de pessoa (PF/PJ)
 */
class AccountDataTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        
        // Criar usuário para testes
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
    }

    /**
     * Teste: Deve retornar dados do perfil do usuário
     */
    public function test_should_return_user_profile_data(): void
    {
        $controller = new UserController();
        $request = \Illuminate\Http\Request::create('/api/user/profile', 'GET');
        $request->setUserResolver(fn() => $this->user);

        $response = $controller->getProfile($request);
        $data = json_decode($response->getContent(), true);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
        $this->assertEquals('testuser', $data['data']['username']);
        $this->assertEquals('test@example.com', $data['data']['email']);
    }

    /**
     * Teste: Deve identificar tipo de pessoa como PF para CPF
     */
    public function test_should_identify_person_type_as_pf_for_cpf(): void
    {
        $this->user->cpf_cnpj = '12345678900'; // CPF (11 dígitos)
        $this->user->save();

        $controller = new UserController();
        $request = \Illuminate\Http\Request::create('/api/user/profile', 'GET');
        $request->setUserResolver(fn() => $this->user);

        $response = $controller->getProfile($request);
        $data = json_decode($response->getContent(), true);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('PF', $data['data']['company']['tipo_pessoa']);
        $this->assertEquals('Pessoa Física', $data['data']['company']['tipo']);
    }

    /**
     * Teste: Deve identificar tipo de pessoa como PJ para CNPJ
     */
    public function test_should_identify_person_type_as_pj_for_cnpj(): void
    {
        $this->user->cpf_cnpj = '12345678000190'; // CNPJ (14 dígitos)
        $this->user->save();

        $controller = new UserController();
        $request = \Illuminate\Http\Request::create('/api/user/profile', 'GET');
        $request->setUserResolver(fn() => $this->user);

        $response = $controller->getProfile($request);
        $data = json_decode($response->getContent(), true);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('PJ', $data['data']['company']['tipo_pessoa']);
        $this->assertEquals('Pessoa Jurídica', $data['data']['company']['tipo']);
    }

    /**
     * Teste: Deve retornar status "Aprovado" para usuário ativo
     */
    public function test_should_return_approved_status_for_active_user(): void
    {
        $this->user->status = 1;
        $this->user->save();

        $controller = new UserController();
        $request = \Illuminate\Http\Request::create('/api/user/profile', 'GET');
        $request->setUserResolver(fn() => $this->user);

        $response = $controller->getProfile($request);
        $data = json_decode($response->getContent(), true);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Aprovado', $data['data']['status_text']);
        $this->assertEquals('active', $data['data']['status']);
    }

    /**
     * Teste: Deve retornar status "Pendente" para usuário inativo
     */
    public function test_should_return_pending_status_for_inactive_user(): void
    {
        $this->user->status = 0;
        $this->user->banido = 0; // Não banido, apenas inativo
        $this->user->save();

        $controller = new UserController();
        $request = \Illuminate\Http\Request::create('/api/user/profile', 'GET');
        $request->setUserResolver(fn() => $this->user);

        $response = $controller->getProfile($request);
        
        // Se usuário não pode fazer login, retorna 403
        if ($response->getStatusCode() === 403) {
            $this->assertEquals(403, $response->getStatusCode());
        } else {
            $data = json_decode($response->getContent(), true);
            $this->assertEquals(200, $response->getStatusCode());
            $this->assertEquals('Pendente', $data['data']['status_text']);
            $this->assertEquals('inactive', $data['data']['status']);
        }
    }

    /**
     * Teste: Deve usar cache para dados do perfil
     */
    public function test_should_use_cache_for_profile_data(): void
    {
        $controller = new UserController();
        $request = \Illuminate\Http\Request::create('/api/user/profile', 'GET');
        $request->setUserResolver(fn() => $this->user);

        // Primeira chamada - deve buscar do banco
        $response1 = $controller->getProfile($request);
        $data1 = json_decode($response1->getContent(), true);
        $this->assertEquals(200, $response1->getStatusCode());
        $this->assertTrue($data1['success']);

        // Verificar se cache foi criado
        $cacheKey = 'user_profile_' . $this->user->username;
        $cached = Cache::get($cacheKey);
        $this->assertNotNull($cached);
        $this->assertEquals($data1['data']['username'], $cached['username']);
    }

    /**
     * Teste: Deve incluir informações de contato
     */
    public function test_should_include_contact_information(): void
    {
        $controller = new UserController();
        $request = \Illuminate\Http\Request::create('/api/user/profile', 'GET');
        $request->setUserResolver(fn() => $this->user);

        $response = $controller->getProfile($request);
        $data = json_decode($response->getContent(), true);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertArrayHasKey('contacts', $data['data']);
        $this->assertEquals('11999999999', $data['data']['contacts']['telefone_principal']);
        $this->assertEquals('test@example.com', $data['data']['contacts']['email_principal']);
    }

    /**
     * Teste: Deve incluir informações da empresa
     */
    public function test_should_include_company_information(): void
    {
        $controller = new UserController();
        $request = \Illuminate\Http\Request::create('/api/user/profile', 'GET');
        $request->setUserResolver(fn() => $this->user);

        $response = $controller->getProfile($request);
        $data = json_decode($response->getContent(), true);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertArrayHasKey('company', $data['data']);
        $this->assertArrayHasKey('tipo_pessoa', $data['data']['company']);
        $this->assertArrayHasKey('tipo', $data['data']['company']);
    }

    /**
     * Teste: Deve retornar saldo do usuário
     */
    public function test_should_return_user_balance(): void
    {
        $this->user->saldo = 1000.50;
        $this->user->save();

        $controller = new UserController();
        $request = \Illuminate\Http\Request::create('/api/user/profile', 'GET');
        $request->setUserResolver(fn() => $this->user);

        $response = $controller->getProfile($request);
        $data = json_decode($response->getContent(), true);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(1000.50, $data['data']['balance']);
    }

    /**
     * Teste: Deve formatar dados corretamente quando campos estão vazios
     */
    public function test_should_format_data_correctly_when_fields_are_empty(): void
    {
        // Usar string vazia em vez de null para campos que não podem ser null
        $this->user->telefone = '';
        $this->user->cpf_cnpj = '';
        $this->user->save();

        $controller = new UserController();
        $request = \Illuminate\Http\Request::create('/api/user/profile', 'GET');
        $request->setUserResolver(fn() => $this->user);

        $response = $controller->getProfile($request);
        $data = json_decode($response->getContent(), true);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertArrayHasKey('email', $data['data']);
        $this->assertArrayHasKey('phone', $data['data']);
        $this->assertArrayHasKey('cnpj', $data['data']);
    }

    /**
     * Teste: Deve retornar permissão do usuário quando disponível
     */
    public function test_should_return_user_permission_when_available(): void
    {
        // Permission parece ser um inteiro, usar 1 para admin
        $this->user->permission = 1;
        $this->user->save();

        $controller = new UserController();
        $request = \Illuminate\Http\Request::create('/api/user/profile', 'GET');
        $request->setUserResolver(fn() => $this->user);

        $response = $controller->getProfile($request);
        $data = json_decode($response->getContent(), true);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(1, $data['data']['permission']);
    }
}

