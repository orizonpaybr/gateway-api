<?php

namespace Tests\Feature\Api;

use Tests\TestCase;
use App\Models\User;
use App\Models\App;
use App\Models\UsersKey;
use App\Models\Adquirente;
use App\Constants\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Testes mínimos para rotas de depósito e saque (API externa).
 *
 * Não dependem de adquirente (Treeal ou outros), certificados ou ambiente.
 * Garantem apenas: autenticação (token+secret) e validação de campos.
 * No futuro, outras adquirentes usarão as mesmas rotas e middlewares.
 */
class DepositWithdrawEssentialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private UsersKey $userKey;

    protected function setUp(): void
    {
        parent::setUp();

        App::factory()->create();
        Adquirente::firstOrCreate(
            ['referencia' => 'treeal'],
            [
                'adquirente' => 'Treeal',
                'status' => 1,
                'url' => 'https://api.example.com',
                'is_default' => 1,
                'is_default_card_billet' => 0,
            ]
        );
        Adquirente::where('referencia', 'treeal')->update(['is_default' => 1, 'status' => 1]);

        $this->user = User::factory()->create([
            'username' => 'apiuser',
            'user_id' => 'apiuser',
            'email' => 'api@test.com',
            'status' => 1,
            'banido' => 0,
            'saldo' => 1000.00,
            'permission' => UserPermission::CLIENT,
            'ips_saque_permitidos' => '127.0.0.1,::1',
        ]);

        $this->userKey = UsersKey::factory()->create([
            'user_id' => $this->user->user_id,
            'token' => 'valid_token_api',
            'secret' => 'valid_secret_api',
        ]);
    }

    // ---- POST /api/wallet/deposit/payment ----

    /** @test */
    public function deposit_rejeita_sem_token(): void
    {
        $response = $this->postJson('/api/wallet/deposit/payment', [
            'secret' => 'valid_secret_api',
            'amount' => 100,
            'debtor_name' => 'Test',
            'email' => 'test@test.com',
        ]);

        $response->assertStatus(400)
            ->assertJsonFragment(['Token ou Secret ausentes']);
    }

    /** @test */
    public function deposit_rejeita_sem_secret(): void
    {
        $response = $this->postJson('/api/wallet/deposit/payment', [
            'token' => 'valid_token_api',
            'amount' => 100,
            'debtor_name' => 'Test',
            'email' => 'test@test.com',
        ]);

        $response->assertStatus(400)
            ->assertJsonFragment(['Token ou Secret ausentes']);
    }

    /** @test */
    public function deposit_rejeita_token_invalido(): void
    {
        $response = $this->postJson('/api/wallet/deposit/payment', [
            'token' => 'token_invalido',
            'secret' => 'valid_secret_api',
            'amount' => 100,
            'debtor_name' => 'Test',
            'email' => 'test@test.com',
        ]);

        $response->assertStatus(401)
            ->assertJsonFragment(['Token ou Secret inválidos']);
    }

    /** @test */
    public function deposit_valida_campos_obrigatorios(): void
    {
        $response = $this->postJson('/api/wallet/deposit/payment', [
            'token' => 'valid_token_api',
            'secret' => 'valid_secret_api',
            // sem amount, debtor_name, email
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['amount', 'debtor_name', 'email']);
    }

    // ---- POST /api/pixout ----

    /** @test */
    public function pixout_rejeita_sem_token(): void
    {
        $response = $this->postJson('/api/pixout', [
            'secret' => 'valid_secret_api',
            'amount' => 100,
            'pixKey' => '11999999999',
            'pixKeyType' => 'phone',
            'baasPostbackUrl' => 'https://example.com/callback',
        ]);

        $response->assertStatus(400)
            ->assertJsonFragment(['Token ou Secret ausentes']);
    }

    /** @test */
    public function pixout_rejeita_sem_secret(): void
    {
        $response = $this->postJson('/api/pixout', [
            'token' => 'valid_token_api',
            'amount' => 100,
            'pixKey' => '11999999999',
            'pixKeyType' => 'phone',
            'baasPostbackUrl' => 'https://example.com/callback',
        ]);

        $response->assertStatus(400)
            ->assertJsonFragment(['Token ou Secret ausentes']);
    }

    /** @test */
    public function pixout_rejeita_token_invalido(): void
    {
        $response = $this->postJson('/api/pixout', [
            'token' => 'token_invalido',
            'secret' => 'valid_secret_api',
            'amount' => 100,
            'pixKey' => '11999999999',
            'pixKeyType' => 'phone',
            'baasPostbackUrl' => 'https://example.com/callback',
        ]);

        $response->assertStatus(401)
            ->assertJsonFragment(['Token ou Secret inválidos']);
    }

    /** @test */
    public function pixout_valida_campos_obrigatorios(): void
    {
        $response = $this->postJson('/api/pixout', [
            'token' => 'valid_token_api',
            'secret' => 'valid_secret_api',
            // sem amount, pixKey, pixKeyType, baasPostbackUrl
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['amount', 'pixKey', 'pixKeyType', 'baasPostbackUrl']);
    }

    /** @test */
    public function pixout_rejeita_pixKeyType_invalido(): void
    {
        $response = $this->postJson('/api/pixout', [
            'token' => 'valid_token_api',
            'secret' => 'valid_secret_api',
            'amount' => 100,
            'pixKey' => '11999999999',
            'pixKeyType' => 'tipo_invalido',
            'baasPostbackUrl' => 'https://example.com/callback',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['pixKeyType']);
    }
}
