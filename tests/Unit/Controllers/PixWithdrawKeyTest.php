<?php

namespace Tests\Unit\Controllers;

use Tests\TestCase;
use App\Models\User;
use App\Models\App;
use App\Constants\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Helpers\AuthTestHelper;

/**
 * Testes Unitários - PIX Saque com Chave
 * 
 * Cobre:
 * - withdraw (realizar saque com chave)
 * - Validação de campos
 * - Autenticação
 * - Verificação de saldo
 * - Verificação de bloqueio de saque
 * - Tratamento de erros
 */
class PixWithdrawKeyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_should_require_authentication()
    {
        $request = \Illuminate\Http\Request::create(
            '/api/pix/withdraw-with-key',
            'POST',
            [
                'key_type' => 'cpf',
                'key_value' => '12345678900',
                'amount' => 100.00,
            ]
        );

        $controller = new \App\Http\Controllers\Api\PixKeyController();
        $response = $controller->withdraw($request);

        $this->assertEquals(401, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertFalse($data['success']);
        $this->assertEquals('Usuário não autenticado', $data['message']);
    }

    public function test_should_validate_key_type_required()
    {
        $user = User::factory()->create(['permission' => UserPermission::CLIENT]);
        
        $request = \Illuminate\Http\Request::create(
            '/api/pix/withdraw-with-key',
            'POST',
            [
                // Sem key_type
                'key_value' => '12345678900',
                'amount' => 100.00,
            ]
        );
        $request->setUserResolver(fn() => $user);

        $controller = new \App\Http\Controllers\Api\PixKeyController();
        $response = $controller->withdraw($request);

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('errors', $data);
    }

    public function test_should_validate_key_value_required()
    {
        $user = User::factory()->create(['permission' => UserPermission::CLIENT]);
        
        $request = \Illuminate\Http\Request::create(
            '/api/pix/withdraw-with-key',
            'POST',
            [
                'key_type' => 'cpf',
                // Sem key_value
                'amount' => 100.00,
            ]
        );
        $request->setUserResolver(fn() => $user);

        $controller = new \App\Http\Controllers\Api\PixKeyController();
        $response = $controller->withdraw($request);

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('errors', $data);
    }

    public function test_should_validate_amount_required()
    {
        $user = User::factory()->create(['permission' => UserPermission::CLIENT]);
        
        $request = \Illuminate\Http\Request::create(
            '/api/pix/withdraw-with-key',
            'POST',
            [
                'key_type' => 'cpf',
                'key_value' => '12345678900',
                // Sem amount
            ]
        );
        $request->setUserResolver(fn() => $user);

        $controller = new \App\Http\Controllers\Api\PixKeyController();
        $response = $controller->withdraw($request);

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('errors', $data);
    }

    public function test_should_validate_amount_minimum()
    {
        $user = User::factory()->create(['permission' => UserPermission::CLIENT]);
        
        $request = \Illuminate\Http\Request::create(
            '/api/pix/withdraw-with-key',
            'POST',
            [
                'key_type' => 'cpf',
                'key_value' => '12345678900',
                'amount' => 0.001, // Menor que mínimo (0.01)
            ]
        );
        $request->setUserResolver(fn() => $user);

        $controller = new \App\Http\Controllers\Api\PixKeyController();
        $response = $controller->withdraw($request);

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('errors', $data);
    }

    public function test_should_validate_key_type_values()
    {
        $user = User::factory()->create(['permission' => UserPermission::CLIENT]);
        
        $request = \Illuminate\Http\Request::create(
            '/api/pix/withdraw-with-key',
            'POST',
            [
                'key_type' => 'invalid_type', // Tipo inválido
                'key_value' => '12345678900',
                'amount' => 100.00,
            ]
        );
        $request->setUserResolver(fn() => $user);

        $controller = new \App\Http\Controllers\Api\PixKeyController();
        $response = $controller->withdraw($request);

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('errors', $data);
    }

    public function test_should_check_balance_sufficient()
    {
        $user = User::factory()->create([
            'permission' => UserPermission::CLIENT,
            'saldo' => 50.00, // Saldo menor que o valor solicitado
        ]);
        
        $request = \Illuminate\Http\Request::create(
            '/api/pix/withdraw-with-key',
            'POST',
            [
                'key_type' => 'cpf',
                'key_value' => '12345678900',
                'amount' => 100.00, // Maior que o saldo
            ]
        );
        $request->setUserResolver(fn() => $user);

        $controller = new \App\Http\Controllers\Api\PixKeyController();
        $response = $controller->withdraw($request);

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertFalse($data['success']);
        $this->assertEquals('Saldo insuficiente', $data['message']);
    }

    public function test_should_check_withdraw_blocked()
    {
        $user = User::factory()->create([
            'permission' => UserPermission::CLIENT,
            'saldo' => 1000.00,
            'saque_bloqueado' => true,
        ]);
        
        $request = \Illuminate\Http\Request::create(
            '/api/pix/withdraw-with-key',
            'POST',
            [
                'key_type' => 'cpf',
                'key_value' => '12345678900',
                'amount' => 100.00,
            ]
        );
        $request->setUserResolver(fn() => $user);

        $controller = new \App\Http\Controllers\Api\PixKeyController();
        $response = $controller->withdraw($request);

        $this->assertEquals(403, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('bloqueado', strtolower($data['message']));
    }

    public function test_should_accept_valid_key_types()
    {
        $validTypes = ['cpf', 'cnpj', 'telefone', 'email', 'aleatoria'];
        
        foreach ($validTypes as $type) {
            $validator = \Illuminate\Support\Facades\Validator::make([
                'key_type' => $type,
                'key_value' => '12345678900',
                'amount' => 100.00,
            ], [
                'key_type' => 'required_without:key_id|in:cpf,cnpj,telefone,email,aleatoria',
                'key_value' => 'required_without:key_id|string',
                'amount' => 'required|numeric|min:0.01',
            ]);

            $this->assertTrue($validator->passes(), "Tipo {$type} deve ser válido");
        }
    }

    public function test_should_validate_description_max_length()
    {
        $user = User::factory()->create(['permission' => UserPermission::CLIENT]);
        
        $request = \Illuminate\Http\Request::create(
            '/api/pix/withdraw-with-key',
            'POST',
            [
                'key_type' => 'cpf',
                'key_value' => '12345678900',
                'amount' => 100.00,
                'description' => str_repeat('a', 256), // Mais que 255 caracteres
            ]
        );
        $request->setUserResolver(fn() => $user);

        $controller = new \App\Http\Controllers\Api\PixKeyController();
        $response = $controller->withdraw($request);

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('errors', $data);
    }
}








