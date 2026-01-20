<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Traits\IPManagementTrait;
use Illuminate\Support\Facades\Log;

class TestIPVerification extends Command
{
    protected $signature = 'test:ip-verification {username} {ip}';
    protected $description = 'Testa verificação de IP para um usuário específico';

    public function handle()
    {
        $username = $this->argument('username');
        $testIP = $this->argument('ip');

        $this->info("=== Teste de Verificação de IP ===");
        $this->newLine();

        $user = User::where('username', $username)->first();
        
        if (!$user) {
            $this->error("Usuário não encontrado: {$username}");
            return 1;
        }

        $this->info("👤 Usuário: {$user->username} (ID: {$user->user_id})");
        $this->line("📋 IPs permitidos (raw): " . ($user->ips_saque_permitidos ?? 'NULL'));
        $this->newLine();

        // Parse dos IPs
        $parsedIPs = IPManagementTrait::parseAllowedIPs($user->ips_saque_permitidos ?? '');
        $this->info("📋 IPs permitidos (parsed): " . json_encode($parsedIPs));
        $this->newLine();

        // Verificar IP global
        $app = \App\Models\App::first();
        $globalIPs = $app ? ($app->global_ips ?? []) : [];
        if (!is_array($globalIPs) && is_string($globalIPs)) {
            $globalIPs = json_decode($globalIPs, true) ?: [];
        }
        $this->info("🌐 IPs globais: " . json_encode($globalIPs));
        $this->newLine();

        // Testar verificação
        $this->info("🔍 Testando IP: {$testIP}");
        $isAllowed = IPManagementTrait::isIPAllowed($testIP, $user);
        
        $this->newLine();
        if ($isAllowed) {
            $this->info("✅ IP AUTORIZADO");
        } else {
            $this->error("❌ IP NÃO AUTORIZADO");
        }

        $this->newLine();
        $this->info("📊 Detalhes:");
        $this->line("   - IP está na lista de permitidos: " . (in_array($testIP, $parsedIPs) ? 'SIM' : 'NÃO'));
        $this->line("   - IP está nos IPs globais: " . (in_array($testIP, $globalIPs) ? 'SIM' : 'NÃO'));
        $this->line("   - Lista de IPs não está vazia: " . (!empty($parsedIPs) ? 'SIM' : 'NÃO'));

        return 0;
    }
}
