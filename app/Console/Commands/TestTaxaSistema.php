<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\App;
use App\Helpers\TaxaSaqueHelper;
use App\Helpers\SaqueInterfaceHelper;

class TestTaxaSistema extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'taxa:test {--user= : ID do usuário para testar} {--valor=1.48 : Valor para testar}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Testa o sistema de taxas de saque';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $userId = $this->option('user');
        $valorTeste = (float) $this->option('valor');
        
        // Buscar usuário
        if ($userId) {
            $user = User::where('user_id', $userId)->orWhere('id', $userId)->first();
        } else {
            $user = User::first();
        }
        
        if (!$user) {
            $this->error('Usuário não encontrado!');
            return 1;
        }
        
        $setting = App::first();
        
        $this->info("=== TESTE DO SISTEMA DE TAXAS ===");
        $this->info("Usuário: {$user->name} (ID: {$user->user_id})");
        $this->info("Saldo atual: R$ " . number_format($user->saldo, 2, ',', '.'));
        $this->info("Valor para teste: R$ " . number_format($valorTeste, 2, ',', '.'));
        $this->line('');
        
        // Testar interface web
        $this->info("=== INTERFACE WEB ===");
        $taxasWeb = TaxaSaqueHelper::calcularTaxaSaque($valorTeste, $setting, $user, true);
        $this->line("Valor solicitado: R$ " . number_format($valorTeste, 2, ',', '.'));
        $this->line("Taxa total: R$ " . number_format($taxasWeb['taxa_cash_out'], 2, ',', '.'));
        $this->line("Valor líquido: R$ " . number_format($taxasWeb['saque_liquido'], 2, ',', '.'));
        $this->line("Total a descontar: R$ " . number_format($taxasWeb['valor_total_descontar'], 2, ',', '.'));
        $this->line("Pode sacar: " . ($user->saldo >= $taxasWeb['valor_total_descontar'] ? 'SIM' : 'NÃO'));
        $this->line('');
        
        // Testar API
        $this->info("=== API ===");
        $taxasApi = TaxaSaqueHelper::calcularTaxaSaque($valorTeste, $setting, $user, false);
        $this->line("Valor solicitado: R$ " . number_format($valorTeste, 2, ',', '.'));
        $this->line("Taxa total: R$ " . number_format($taxasApi['taxa_cash_out'], 2, ',', '.'));
        $this->line("Valor líquido: R$ " . number_format($taxasApi['saque_liquido'], 2, ',', '.'));
        $this->line("Total a descontar: R$ " . number_format($taxasApi['valor_total_descontar'], 2, ',', '.'));
        $this->line("Pode sacar: " . ($user->saldo >= $taxasApi['valor_total_descontar'] ? 'SIM' : 'NÃO'));
        $this->line('');
        
        // Calcular valor máximo
        $this->info("=== VALOR MÁXIMO PARA SAQUE ===");
        $valorMaximoWeb = TaxaSaqueHelper::calcularValorMaximoSaque($user->saldo, $setting, $user, true);
        $this->line("Interface Web:");
        $this->line("  Valor máximo: R$ " . number_format($valorMaximoWeb['valor_maximo'], 2, ',', '.'));
        $this->line("  Taxa total: R$ " . number_format($valorMaximoWeb['taxa_total'], 2, ',', '.'));
        $this->line("  Saldo restante: R$ " . number_format($valorMaximoWeb['saldo_restante'], 2, ',', '.'));
        
        $valorMaximoApi = TaxaSaqueHelper::calcularValorMaximoSaque($user->saldo, $setting, $user, false);
        $this->line("API:");
        $this->line("  Valor máximo: R$ " . number_format($valorMaximoApi['valor_maximo'], 2, ',', '.'));
        $this->line("  Taxa total: R$ " . number_format($valorMaximoApi['taxa_total'], 2, ',', '.'));
        $this->line("  Saldo restante: R$ " . number_format($valorMaximoApi['saldo_restante'], 2, ',', '.'));
        $this->line('');
        
        // Testar com diferentes valores
        $this->info("=== TESTE COM DIFERENTES VALORES ===");
        $valores = [0.47, 0.48, 1.00, 1.48, 2.00];
        
        foreach ($valores as $valor) {
            $taxas = TaxaSaqueHelper::calcularTaxaSaque($valor, $setting, $user, true);
            $podeSacar = $user->saldo >= $taxas['valor_total_descontar'];
            $this->line("R$ " . number_format($valor, 2, ',', '.') . " -> " . 
                      ($podeSacar ? 'PODE' : 'NÃO PODE') . " sacar (Total: R$ " . 
                      number_format($taxas['valor_total_descontar'], 2, ',', '.') . ")");
        }
        
        return 0;
    }
}

