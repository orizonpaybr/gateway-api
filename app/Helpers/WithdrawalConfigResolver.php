<?php

namespace App\Helpers;

use App\Models\User;

class WithdrawalConfigResolver
{
    /**
     * Resolve se o saque deve ser automático e qual limite usar.
     * Com config personalizada, limite null no usuário significa sem limite (não herda global).
     *
     * @return array{saque_automatico: bool, limite: float|null}
     */
    public static function resolve(User $user, $setting): array
    {
        if ($user->saque_config_personalizada) {
            $saqueAutomatico = $user->saque_automatico_usuario ?? (bool) $setting->saque_automatico;
            $limite = $user->limite_saque_automatico_usuario !== null
                ? (float) $user->limite_saque_automatico_usuario
                : null;
        } else {
            $saqueAutomatico = (bool) $setting->saque_automatico;
            $limite = $setting->limite_saque_automatico !== null
                ? (float) $setting->limite_saque_automatico
                : null;
        }

        return [
            'saque_automatico' => $saqueAutomatico,
            'limite' => $limite,
        ];
    }

    /**
     * Determina se o valor deve ser processado automaticamente.
     */
    public static function isAutomatico(User $user, $setting, float $amount): bool
    {
        $config = self::resolve($user, $setting);

        if (!$config['saque_automatico']) {
            return false;
        }

        $temLimite = $config['limite'] !== null && $config['limite'] > 0;

        return !$temLimite || $amount <= $config['limite'];
    }

    /**
     * Mensagem exibida ao usuário quando o saque exige aprovação manual.
     */
    public static function getMotivoManual(User $user, $setting): string
    {
        $config = self::resolve($user, $setting);

        if (!$config['saque_automatico']) {
            return $user->saque_config_personalizada
                ? 'Saque automático desativado para este usuário'
                : 'Saque automático desativado no sistema';
        }

        $limite = $config['limite'];
        if ($limite !== null && $limite > 0) {
            return 'Valor acima do limite automático de R$ '.number_format($limite, 2, ',', '.');
        }

        return 'Aguardando aprovação do administrador.';
    }
}
