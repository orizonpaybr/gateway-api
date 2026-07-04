<?php

namespace App\Services;

use App\Models\User;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorVerificationService
{
    public function __construct(
        private Google2FA $google2fa,
        private TotpMigrationService $totpMigration,
    ) {}

    public function verify(User $user, string $code): bool
    {
        if ($this->totpMigration->mustMigrateBeforeVerify($user)) {
            return false;
        }

        $method = $user->twofa_method ?? ($user->twofa_secret ? 'totp' : 'pin');

        if ($method === 'totp' && $user->twofa_secret) {
            try {
                $secret = decrypt($user->twofa_secret);
            } catch (\Throwable) {
                $secret = $user->twofa_secret;
            }

            return $this->google2fa->verifyKey($secret, $code);
        }

        return false;
    }

    public function usesTotp(User $user): bool
    {
        return ($user->twofa_method === 'totp' || ($user->twofa_secret && ! $user->twofa_pin));
    }

    public function migrationRequiredMessage(): string
    {
        return 'Seu método de autenticação precisa ser migrado para TOTP. Acesse as configurações de segurança e configure o app autenticador.';
    }
}
