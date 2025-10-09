<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Traits\IPManagementTrait;

class TestIPSystem extends Command
{
    protected $signature = 'ip:test {user_id} {ip}';
    protected $description = 'Testa o sistema de verificação de IPs';

    public function handle()
    {
        $userId = $this->argument('user_id');
        $testIP = $this->argument('ip');

        $user = User::where('user_id', $userId)->first();
        
        if (!$user) {
            $this->error("Usuário {$userId} não encontrado!");
            return 1;
        }

        $this->info("🧪 Testando sistema de IPs para usuário: {$user->name}");
        $this->info("📧 Email: {$user->email}");
        $this->info("🔍 IP de teste: {$testIP}");
        
        // Mostrar IPs atuais
        $allowedIPs = IPManagementTrait::getAllowedIPs($user);
        $this->info("📋 IPs permitidos atuais: " . (empty($allowedIPs) ? 'Nenhum' : implode(', ', $allowedIPs)));
        
        // Testar validação
        $isValid = IPManagementTrait::isValidIP($testIP);
        $this->info("✅ IP válido: " . ($isValid ? 'Sim' : 'Não'));
        
        if ($isValid) {
            $isAllowed = IPManagementTrait::isIPAllowed($testIP, $user);
            $this->info("🔐 IP autorizado: " . ($isAllowed ? 'Sim' : 'Não'));
            
            if (!$isAllowed) {
                $this->warn("⚠️  Para autorizar este IP, adicione-o na configuração do usuário:");
                $this->line("   - Acesse: /my-profile");
                $this->line("   - Aba: Credenciais");
                $this->line("   - Adicione: {$testIP}");
            }
        }
        
        return 0;
    }
}
