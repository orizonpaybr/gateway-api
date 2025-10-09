<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\App;
use App\Helpers\TaxaSaqueHelper;

class TestSaldoInsuficiente extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'taxa:test-saldo-insuficiente {--user= : ID do usuário para testar}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Testa validação de saldo insuficiente para saques';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $userId = $this->option('user');
        
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
        
        $this->info("=== TESTE DE SALDO INSUFICIENTE ===");
        $this->info("Usuário: {$user->name} (ID: {$user->user_id})");
        $this->info("Saldo atual: R$ " . number_format($user->saldo, 2, ',', '.'));
        $this->line('');
        
        // Cenários de teste
        $cenarios = [
            ['valor' => 2.00, 'saldo' => 2.50, 'descricao' => 'Saldo insuficiente (R$ 2,50 para R$ 2,00)'],
            ['valor' => 1.00, 'saldo' => 1.50, 'descricao' => 'Saldo insuficiente (R$ 1,50 para R$ 1,00)'],
            ['valor' => 0.50, 'saldo' => 1.00, 'descricao' => 'Saldo insuficiente (R$ 1,00 para R$ 0,50)'],
            ['valor' => 5.00, 'saldo' => 10.00, 'descricao' => 'Saldo suficiente (R$ 10,00 para R$ 5,00)'],
        ];
        
        foreach ($cenarios as $cenario) {
            $this->info("--- {$cenario['descricao']} ---");
            
            // Simular saldo
            $saldoOriginal = $user->saldo;
            $user->saldo = $cenario['saldo'];
            $user->save();
            
            // Calcular taxas
            $taxasWeb = TaxaSaqueHelper::calcularTaxaSaque($cenario['valor'], $setting, $user, true);
            $taxasApi = TaxaSaqueHelper::calcularTaxaSaque($cenario['valor'], $setting, $user, false);
            
            // Verificar se pode sacar
            $podeSacarWeb = $user->saldo >= $taxasWeb['valor_total_descontar'];
            $podeSacarApi = $user->saldo >= $taxasApi['valor_total_descontar'];
            
            $this->line("Valor solicitado: R$ " . number_format($cenario['valor'], 2, ',', '.'));
            $this->line("Saldo disponível: R$ " . number_format($user->saldo, 2, ',', '.'));
            $this->line("Taxa total (Web): R$ " . number_format($taxasWeb['taxa_cash_out'], 2, ',', '.'));
            $this->line("Taxa total (API): R$ " . number_format($taxasApi['taxa_cash_out'], 2, ',', '.'));
            $this->line("Total necessário (Web): R$ " . number_format($taxasWeb['valor_total_descontar'], 2, ',', '.'));
            $this->line("Total necessário (API): R$ " . number_format($taxasApi['valor_total_descontar'], 2, ',', '.'));
            $this->line("Pode sacar (Web): " . ($podeSacarWeb ? 'SIM' : 'NÃO'));
            $this->line("Pode sacar (API): " . ($podeSacarApi ? 'SIM' : 'NÃO'));
            
            if (!$podeSacarWeb) {
                $deficit = $taxasWeb['valor_total_descontar'] - $user->saldo;
                $this->line("Deficit (Web): R$ " . number_format($deficit, 2, ',', '.'));
            }
            
            if (!$podeSacarApi) {
                $deficit = $taxasApi['valor_total_descontar'] - $user->saldo;
                $this->line("Deficit (API): R$ " . number_format($deficit, 2, ',', '.'));
            }
            
            $this->line('');
        }
        
        // Restaurar saldo original
        $user->saldo = $saldoOriginal;
        $user->save();
        
        $this->info("Saldo original restaurado: R$ " . number_format($user->saldo, 2, ',', '.'));
        
        return 0;
    }
}

