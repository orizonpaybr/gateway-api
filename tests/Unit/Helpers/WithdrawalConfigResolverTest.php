<?php

namespace Tests\Unit\Helpers;

use App\Helpers\WithdrawalConfigResolver;
use App\Models\User;
use PHPUnit\Framework\TestCase;

class WithdrawalConfigResolverTest extends TestCase
{
    private function makeSetting(bool $saqueAutomatico, ?float $limite): object
    {
        return (object) [
            'saque_automatico' => $saqueAutomatico,
            'limite_saque_automatico' => $limite,
        ];
    }

    private function makeUser(array $attrs): User
    {
        $user = new User();
        $user->forceFill(array_merge([
            'saque_config_personalizada' => false,
            'saque_automatico_usuario' => null,
            'limite_saque_automatico_usuario' => null,
        ], $attrs));

        return $user;
    }

    public function test_global_config_uses_global_limit(): void
    {
        $user = $this->makeUser(['saque_config_personalizada' => false]);
        $setting = $this->makeSetting(true, 5000.0);

        $config = WithdrawalConfigResolver::resolve($user, $setting);

        $this->assertTrue($config['saque_automatico']);
        $this->assertSame(5000.0, $config['limite']);
        $this->assertTrue(WithdrawalConfigResolver::isAutomatico($user, $setting, 3000.0));
        $this->assertFalse(WithdrawalConfigResolver::isAutomatico($user, $setting, 6000.0));
    }

    public function test_personalized_automatic_without_limit_ignores_global_limit(): void
    {
        $user = $this->makeUser([
            'saque_config_personalizada' => true,
            'saque_automatico_usuario' => true,
            'limite_saque_automatico_usuario' => null,
        ]);
        $setting = $this->makeSetting(true, 5000.0);

        $config = WithdrawalConfigResolver::resolve($user, $setting);

        $this->assertTrue($config['saque_automatico']);
        $this->assertNull($config['limite']);
        $this->assertTrue(WithdrawalConfigResolver::isAutomatico($user, $setting, 10000.0));
    }

    public function test_personalized_hybrid_uses_user_limit(): void
    {
        $user = $this->makeUser([
            'saque_config_personalizada' => true,
            'saque_automatico_usuario' => true,
            'limite_saque_automatico_usuario' => 2000.0,
        ]);
        $setting = $this->makeSetting(true, 5000.0);

        $config = WithdrawalConfigResolver::resolve($user, $setting);

        $this->assertTrue($config['saque_automatico']);
        $this->assertSame(2000.0, $config['limite']);
        $this->assertTrue(WithdrawalConfigResolver::isAutomatico($user, $setting, 2000.0));
        $this->assertFalse(WithdrawalConfigResolver::isAutomatico($user, $setting, 2000.01));
    }

    public function test_personalized_manual_is_always_manual(): void
    {
        $user = $this->makeUser([
            'saque_config_personalizada' => true,
            'saque_automatico_usuario' => false,
            'limite_saque_automatico_usuario' => null,
        ]);
        $setting = $this->makeSetting(true, 5000.0);

        $config = WithdrawalConfigResolver::resolve($user, $setting);

        $this->assertFalse($config['saque_automatico']);
        $this->assertFalse(WithdrawalConfigResolver::isAutomatico($user, $setting, 100.0));
    }

    public function test_get_motivo_manual_uses_personalized_limit(): void
    {
        $user = $this->makeUser([
            'saque_config_personalizada' => true,
            'saque_automatico_usuario' => true,
            'limite_saque_automatico_usuario' => 100.0,
        ]);
        $setting = $this->makeSetting(true, 15000.0);

        $motivo = WithdrawalConfigResolver::getMotivoManual($user, $setting);

        $this->assertSame(
            'Valor acima do limite automático de R$ 100,00',
            $motivo
        );
    }

    public function test_get_motivo_manual_uses_global_limit_when_not_personalized(): void
    {
        $user = $this->makeUser(['saque_config_personalizada' => false]);
        $setting = $this->makeSetting(true, 15000.0);

        $motivo = WithdrawalConfigResolver::getMotivoManual($user, $setting);

        $this->assertSame(
            'Valor acima do limite automático de R$ 15.000,00',
            $motivo
        );
    }

    public function test_get_motivo_manual_when_automatic_disabled_globally(): void
    {
        $user = $this->makeUser(['saque_config_personalizada' => false]);
        $setting = $this->makeSetting(false, 5000.0);

        $motivo = WithdrawalConfigResolver::getMotivoManual($user, $setting);

        $this->assertSame('Saque automático desativado no sistema', $motivo);
    }

    public function test_get_motivo_manual_when_automatic_disabled_for_user(): void
    {
        $user = $this->makeUser([
            'saque_config_personalizada' => true,
            'saque_automatico_usuario' => false,
        ]);
        $setting = $this->makeSetting(true, 5000.0);

        $motivo = WithdrawalConfigResolver::getMotivoManual($user, $setting);

        $this->assertSame('Saque automático desativado para este usuário', $motivo);
    }
}
