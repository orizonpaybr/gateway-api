<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AddAllowedIPDirect extends Command
{
    protected $signature = 'ip:add-direct {username} {ip}';
    protected $description = 'Adiciona IP permitido diretamente no banco (bypass do trait)';

    public function handle()
    {
        $username = $this->argument('username');
        $ip = $this->argument('ip');

        $this->info("=== Adicionar IP Diretamente no Banco ===");
        $this->newLine();

        $user = User::where('username', $username)->first();
        
        if (!$user) {
            $this->error("Usuário não encontrado: {$username}");
            return 1;
        }

        $this->info("👤 Usuário: {$user->username}");
        
        // Obter IPs atuais
        $currentIPsJson = $user->ips_saque_permitidos;
        $currentIPs = [];
        
        if ($currentIPsJson) {
            $currentIPs = json_decode($currentIPsJson, true) ?: [];
        }

        $this->line("📋 IPs atuais: " . (empty($currentIPs) ? 'Nenhum' : implode(', ', $currentIPs)));
        $this->newLine();

        // Verificar se já existe
        if (in_array($ip, $currentIPs)) {
            $this->warn("⚠️  IP já está na lista!");
            return 0;
        }

        // Adicionar IP
        $currentIPs[] = $ip;
        $newIPsJson = json_encode($currentIPs);

        $this->info("💾 Salvando IPs: {$newIPsJson}");

        // Usar update direto
        $updated = DB::table('users')
            ->where('username', $username)
            ->update(['ips_saque_permitidos' => $newIPsJson]);

        if ($updated) {
            $this->info("✅ IP adicionado com sucesso!");
            
            // Verificar
            $user->refresh();
            $this->line("📋 IPs após save: " . ($user->ips_saque_permitidos ?? 'NULL'));
            
            $savedIPs = json_decode($user->ips_saque_permitidos ?? '[]', true);
            $this->line("📋 IPs parseados: " . implode(', ', $savedIPs));
            
            return 0;
        } else {
            $this->error("❌ Erro ao salvar IP");
            return 1;
        }
    }
}
