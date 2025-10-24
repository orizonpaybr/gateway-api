<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class Reset2FA extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:reset-2fa {username} {pin}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset 2FA PIN for a user';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $username = $this->argument('username');
        $pin = $this->argument('pin');

        // Validar PIN
        if (strlen($pin) !== 6 || !ctype_digit($pin)) {
            $this->error('PIN deve ter exatamente 6 dígitos numéricos');
            return 1;
        }

        // Buscar usuário
        $user = User::where('username', $username)->first();

        if (!$user) {
            $this->error("Usuário '{$username}' não encontrado");
            return 1;
        }

        // Criar hash
        $hash = bcrypt($pin);

        // Testar hash
        if (!Hash::check($pin, $hash)) {
            $this->error('Falha ao criar hash válido');
            return 1;
        }

        // Salvar
        $user->twofa_pin = $hash;
        $user->twofa_enabled = true;
        $user->twofa_enabled_at = now();
        $user->save();

        // Verificar
        $user->refresh();
        if (Hash::check($pin, $user->twofa_pin)) {
            $this->info("✅ 2FA resetado com sucesso para '{$username}'");
            $this->info("📌 Novo PIN: {$pin}");
            return 0;
        } else {
            $this->error('❌ Falha ao verificar após salvar');
            return 1;
        }
    }
}

