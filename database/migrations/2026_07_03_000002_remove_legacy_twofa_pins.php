<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remove PIN estático legado — em produção todo usuário deve usar TOTP (Google Authenticator).
 * Quem tinha apenas PIN precisará configurar o app autenticador no próximo login.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereNotNull('twofa_pin')
            ->where(function ($query) {
                $query->whereNull('twofa_secret')
                    ->orWhere('twofa_method', 'pin')
                    ->orWhereNull('twofa_method');
            })
            ->update([
                'twofa_pin' => null,
                'twofa_method' => null,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // PINs eram hasheados — não é possível restaurar os valores originais.
    }
};
