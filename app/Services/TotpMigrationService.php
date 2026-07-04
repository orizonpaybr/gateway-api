<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;

class TotpMigrationService
{
    public function requiresMigration(User $user): bool
    {
        if (! $user->twofa_enabled) {
            return false;
        }

        return $user->twofa_method === 'pin'
            || ($user->twofa_pin && ! $user->twofa_secret);
    }

    public function migrationDeadlinePassed(): bool
    {
        $deadline = config('auth_security.totp_migration_deadline');

        if (empty($deadline)) {
            return false;
        }

        try {
            return Carbon::parse($deadline)->isPast();
        } catch (\Throwable) {
            return false;
        }
    }

    public function mustMigrateBeforeVerify(User $user): bool
    {
        return $this->requiresMigration($user) && $this->migrationDeadlinePassed();
    }
}
