<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\App;
use App\Helpers\TaxaFlexivelHelper;

class TestTaxasGlobais extends Command
{
    protected $signature = 'test:taxas-globais';
    protected $description = 'Testa se o sistema está usando apenas configurações globais';

    public function handle()
    {
        $this->info('🧪 Testando sistema de taxas globais...');
        
        // Buscar configurações globais
        $setting = App::first();
        
        $this->info("📊 Configurações globais atuais:");
        $this->line("   Taxa Depósito (%): {$setting->taxa_cash_in_padrao}%");
        $this->line("   Taxa Depósito Fixa (R$): R$ {$setting->taxa_fixa_padrao}");
        $this->line("   Taxa Baseline (R$): R$ {$setting->baseline}");
        $this->line("   Sistema Flexível Ativo: " . ($setting->taxa_flexivel_ativa ? 'Sim' : 'Não'));
        
        if ($setting->taxa_flexivel_ativa) {
            $this->line("   Valor Mínimo Flexível: R$ {$setting->taxa_flexivel_valor_minimo}");
            $this->line("   Taxa Fixa Baixo: R$ {$setting->taxa_flexivel_fixa_baixo}");
            $this->line("   Taxa % Alto: {$setting->taxa_flexivel_percentual_alto}%");
        }
        
        $this->newLine();
        
        // Testar cálculo para R$ 10,00
        $this->info("🧮 Testando cálculo para depósito de R$ 10,00:");
        
        $taxaCalculada = TaxaFlexivelHelper::calcularTaxaDeposito(10.00, $setting, null);
        
        $this->line("   Valor bruto: R$ 10,00");
        $this->line("   Taxa calculada: R$ {$taxaCalculada['taxa_cash_in']}");
        $this->line("   Valor líquido: R$ {$taxaCalculada['deposito_liquido']}");
        $this->line("   Descrição: {$taxaCalculada['descricao']}");
        
        $this->newLine();
        
        // Verificar se está usando apenas configurações globais
        if (strpos($taxaCalculada['descricao'], 'GLOBAL') !== false || 
            strpos($taxaCalculada['descricao'], 'PADRAO') !== false) {
            $this->info("✅ Sistema está usando apenas configurações globais!");
        } else {
            $this->error("❌ Sistema ainda pode estar usando configurações de usuário!");
        }
        
        $this->newLine();
        $this->info("🎯 Teste concluído! O sistema agora usa apenas as configurações de:");
        $this->line("   https://app.swiftpay.cloud/admin/ajustes/gerais");
    }
}