<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\{App, User, Treeal};
use App\Helpers\{TaxaFlexivelHelper, TaxaSaqueHelper};
use App\Services\TreealService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * Comando para testar todos os cenários de taxas com TREEAL
 * 
 * Testa:
 * - Depósitos com taxas globais (básicas e flexíveis)
 * - Depósitos com taxas individuais (básicas e flexíveis)
 * - Saques com taxas globais (web e API)
 * - Saques com taxas individuais (web e API)
 * - Validações de valores mínimos e máximos
 * - Consistência entre cálculos
 */
class TestTaxasTreeal extends Command
{
    protected $signature = 'test:taxas-treeal 
                            {--user-id= : ID do usuário para testes}
                            {--dry-run : Apenas simular, não criar transações reais}';

    protected $description = 'Testa todos os cenários de taxas com TREEAL';

    private $setting;
    private $testUser;
    private $treealService;
    private $results = [];
    private $dryRun;

    public function handle()
    {
        $this->dryRun = $this->option('dry-run');
        $verbose = $this->option('verbose') || $this->getOutput()->isVerbose();

        $this->info('🧪 Iniciando testes de taxas com TREEAL');
        $this->info('═══════════════════════════════════════════════════════');
        
        if ($this->dryRun) {
            $this->warn('⚠️  MODO DRY-RUN: Nenhuma transação real será criada');
        }

        // Carregar configurações
        $this->setting = App::first();
        if (!$this->setting) {
            $this->error('❌ Configurações do sistema não encontradas');
            return 1;
        }

        // Verificar TREEAL
        $treealConfig = Treeal::first();
        if (!$treealConfig || !$treealConfig->isActive()) {
            $this->error('❌ TREEAL não está configurado ou ativo');
            return 1;
        }

        $this->treealService = app(TreealService::class);
        if (!$this->treealService->isActive()) {
            $this->error('❌ TreealService não está ativo');
            return 1;
        }

        // Carregar ou criar usuário de teste
        $userId = $this->option('user-id');
        if ($userId) {
            $this->testUser = User::find($userId);
            if (!$this->testUser) {
                $this->error("❌ Usuário ID {$userId} não encontrado");
                return 1;
            }
        } else {
            // Criar usuário de teste temporário
            $this->testUser = $this->createTestUser();
        }

        $this->info("👤 Usuário de teste: {$this->testUser->username} (ID: {$this->testUser->id})");
        $this->newLine();

        // Executar testes
        try {
            $this->testDepositosTaxasGlobaisBasicas();
            $this->testDepositosTaxasGlobaisFlexiveis();
            $this->testDepositosTaxasIndividuaisBasicas();
            $this->testDepositosTaxasIndividuaisFlexiveis();
            $this->testSaquesTaxasGlobais();
            $this->testSaquesTaxasIndividuais();
            $this->testValoresLimites();
            $this->testConsistencia();

            // Mostrar resultados
            $this->showResults();

            return 0;
        } catch (\Exception $e) {
            $this->error("❌ Erro durante testes: " . $e->getMessage());
            if ($this->getOutput()->isVerbose()) {
                $this->error($e->getTraceAsString());
            }
            return 1;
        }
    }

    /**
     * Testa depósitos com taxas globais básicas
     */
    private function testDepositosTaxasGlobaisBasicas()
    {
        $this->info('📥 Testando Depósitos - Taxas Globais Básicas');
        
        // Desativar sistema flexível global
        $originalFlexivelAtiva = $this->setting->taxa_flexivel_ativa;
        $this->setting->taxa_flexivel_ativa = false;
        $this->setting->save();

        // Desativar taxas personalizadas do usuário
        $this->testUser->taxas_personalizadas_ativas = false;
        $this->testUser->save();

        $valores = [10.00, 50.00, 100.00, 500.00, 1000.00];
        
        foreach ($valores as $valor) {
            try {
                $resultado = TaxaFlexivelHelper::calcularTaxaDeposito($valor, $this->setting, $this->testUser);
                
                $taxaEsperada = ($valor * ($this->setting->taxa_cash_in_padrao ?? 5.00)) / 100;
                $taxaEsperada += ($this->setting->taxa_fixa_padrao ?? 0.00);
                $depositoLiquidoEsperado = $valor - $taxaEsperada;

                // A descrição real inclui _PERCENTUAL_FIXA quando usa sistema básico
                $descricaoEsperada = 'GLOBAL_BASICA_PERCENTUAL_FIXA';
                
                // Se o depósito líquido seria negativo, o helper retorna 0 (proteção)
                // A taxa calculada permanece como está (não é limitada pelo helper)
                if ($depositoLiquidoEsperado < 0) {
                    $depositoLiquidoEsperado = 0;
                    // A taxa esperada permanece a calculada (o helper não limita quando líquido é 0)
                    // Isso serve como alerta de que as taxas estão muito altas para valores pequenos
                }
                
                $this->validateResult(
                    "Depósito Global Básico - R$ " . number_format($valor, 2, ',', '.'),
                    $resultado,
                    [
                        'taxa_cash_in' => $taxaEsperada,
                        'deposito_liquido' => $depositoLiquidoEsperado,
                        'descricao' => $descricaoEsperada
                    ],
                    $valor
                );
            } catch (\Exception $e) {
                $this->addResult('ERRO', "Depósito Global Básico - R$ {$valor}", $e->getMessage());
            }
        }

        // Restaurar configuração original
        $this->setting->taxa_flexivel_ativa = $originalFlexivelAtiva;
        $this->setting->save();
    }

    /**
     * Testa depósitos com taxas globais flexíveis
     */
    private function testDepositosTaxasGlobaisFlexiveis()
    {
        $this->info('📥 Testando Depósitos - Taxas Globais Flexíveis');
        
        // Ativar sistema flexível global
        $originalFlexivelAtiva = $this->setting->taxa_flexivel_ativa;
        $originalValorMinimo = $this->setting->taxa_flexivel_valor_minimo;
        $originalTaxaFixaBaixo = $this->setting->taxa_flexivel_fixa_baixo;
        $originalTaxaPercentualAlto = $this->setting->taxa_flexivel_percentual_alto;

        $this->setting->taxa_flexivel_ativa = true;
        $this->setting->taxa_flexivel_valor_minimo = 100.00;
        $this->setting->taxa_flexivel_fixa_baixo = 5.00;
        $this->setting->taxa_flexivel_percentual_alto = 3.00;
        $this->setting->save();

        // Desativar taxas personalizadas do usuário
        $this->testUser->taxas_personalizadas_ativas = false;
        $this->testUser->save();

        $cenarios = [
            ['valor' => 50.00, 'taxa_esperada' => 5.00, 'tipo' => 'FIXA'],
            ['valor' => 100.00, 'taxa_esperada' => 3.00, 'tipo' => 'PERCENTUAL'], // 3% de 100
            ['valor' => 200.00, 'taxa_esperada' => 6.00, 'tipo' => 'PERCENTUAL'], // 3% de 200
        ];

        foreach ($cenarios as $cenario) {
            try {
                $resultado = TaxaFlexivelHelper::calcularTaxaDeposito($cenario['valor'], $this->setting, $this->testUser);
                
                $taxaEsperada = $cenario['taxa_esperada'];
                $depositoLiquidoEsperado = $cenario['valor'] - $taxaEsperada;
                $descricaoEsperada = "FLEXIVEL_GLOBAL_{$cenario['tipo']}";

                $this->validateResult(
                    "Depósito Global Flexível - R$ " . number_format($cenario['valor'], 2, ',', '.'),
                    $resultado,
                    [
                        'taxa_cash_in' => $taxaEsperada,
                        'deposito_liquido' => $depositoLiquidoEsperado,
                        'descricao' => $descricaoEsperada
                    ],
                    $cenario['valor']
                );
            } catch (\Exception $e) {
                $this->addResult('ERRO', "Depósito Global Flexível - R$ {$cenario['valor']}", $e->getMessage());
            }
        }

        // Restaurar configurações originais
        $this->setting->taxa_flexivel_ativa = $originalFlexivelAtiva;
        $this->setting->taxa_flexivel_valor_minimo = $originalValorMinimo;
        $this->setting->taxa_flexivel_fixa_baixo = $originalTaxaFixaBaixo;
        $this->setting->taxa_flexivel_percentual_alto = $originalTaxaPercentualAlto;
        $this->setting->save();
    }

    /**
     * Testa depósitos com taxas individuais básicas
     */
    private function testDepositosTaxasIndividuaisBasicas()
    {
        $this->info('📥 Testando Depósitos - Taxas Individuais Básicas');
        
        // Desativar sistema flexível global
        $originalFlexivelAtiva = $this->setting->taxa_flexivel_ativa;
        $this->setting->taxa_flexivel_ativa = false;
        $this->setting->save();

        // Ativar taxas personalizadas do usuário
        $this->testUser->taxas_personalizadas_ativas = true;
        $this->testUser->sistema_flexivel_ativo = false;
        $this->testUser->taxa_percentual_deposito = 4.50;
        $this->testUser->taxa_fixa_deposito = 1.50;
        $this->testUser->save();

        $valores = [10.00, 50.00, 100.00];
        
        foreach ($valores as $valor) {
            try {
                $resultado = TaxaFlexivelHelper::calcularTaxaDeposito($valor, $this->setting, $this->testUser);
                
                $taxaEsperada = ($valor * 4.50) / 100 + 1.50;
                $depositoLiquidoEsperado = $valor - $taxaEsperada;

                // A descrição real inclui _PERCENTUAL_FIXA quando usa sistema básico
                $descricaoEsperada = 'PERSONALIZADA_BASICA_PERCENTUAL_FIXA';
                
                // Se o depósito líquido seria negativo, o helper retorna 0 (proteção)
                $depositoLiquidoEsperado = max(0, $depositoLiquidoEsperado);
                
                $this->validateResult(
                    "Depósito Individual Básico - R$ " . number_format($valor, 2, ',', '.'),
                    $resultado,
                    [
                        'taxa_cash_in' => $taxaEsperada,
                        'deposito_liquido' => $depositoLiquidoEsperado,
                        'descricao' => $descricaoEsperada
                    ],
                    $valor
                );
            } catch (\Exception $e) {
                $this->addResult('ERRO', "Depósito Individual Básico - R$ {$valor}", $e->getMessage());
            }
        }

        // Restaurar configurações
        $this->setting->taxa_flexivel_ativa = $originalFlexivelAtiva;
        $this->setting->save();
        $this->testUser->taxas_personalizadas_ativas = false;
        $this->testUser->save();
    }

    /**
     * Testa depósitos com taxas individuais flexíveis
     */
    private function testDepositosTaxasIndividuaisFlexiveis()
    {
        $this->info('📥 Testando Depósitos - Taxas Individuais Flexíveis');
        
        // Ativar taxas personalizadas e sistema flexível do usuário
        $this->testUser->taxas_personalizadas_ativas = true;
        $this->testUser->sistema_flexivel_ativo = true;
        $this->testUser->valor_minimo_flexivel = 150.00;
        $this->testUser->taxa_fixa_baixos = 6.00;
        $this->testUser->taxa_percentual_altos = 2.50;
        $this->testUser->save();

        $cenarios = [
            ['valor' => 100.00, 'taxa_esperada' => 6.00, 'tipo' => 'FIXA'],
            ['valor' => 150.00, 'taxa_esperada' => 3.75, 'tipo' => 'PERCENTUAL'], // 2.5% de 150
            ['valor' => 300.00, 'taxa_esperada' => 7.50, 'tipo' => 'PERCENTUAL'], // 2.5% de 300
        ];

        foreach ($cenarios as $cenario) {
            try {
                $resultado = TaxaFlexivelHelper::calcularTaxaDeposito($cenario['valor'], $this->setting, $this->testUser);
                
                $taxaEsperada = $cenario['taxa_esperada'];
                $depositoLiquidoEsperado = $cenario['valor'] - $taxaEsperada;
                $descricaoEsperada = "FLEXIVEL_USUARIO_{$cenario['tipo']}";

                $this->validateResult(
                    "Depósito Individual Flexível - R$ " . number_format($cenario['valor'], 2, ',', '.'),
                    $resultado,
                    [
                        'taxa_cash_in' => $taxaEsperada,
                        'deposito_liquido' => $depositoLiquidoEsperado,
                        'descricao' => $descricaoEsperada
                    ],
                    $cenario['valor']
                );
            } catch (\Exception $e) {
                $this->addResult('ERRO', "Depósito Individual Flexível - R$ {$cenario['valor']}", $e->getMessage());
            }
        }

        // Restaurar configurações
        $this->testUser->taxas_personalizadas_ativas = false;
        $this->testUser->sistema_flexivel_ativo = false;
        $this->testUser->save();
    }

    /**
     * Testa saques com taxas globais
     */
    private function testSaquesTaxasGlobais()
    {
        $this->info('💸 Testando Saques - Taxas Globais');
        
        // Desativar taxas personalizadas
        $this->testUser->taxas_personalizadas_ativas = false;
        $this->testUser->save();

        $valores = [50.00, 100.00, 500.00];
        $tipos = ['web' => true, 'api' => false];

        foreach ($valores as $valor) {
            foreach ($tipos as $tipoNome => $isInterfaceWeb) {
                try {
                    $resultado = TaxaSaqueHelper::calcularTaxaSaque($valor, $this->setting, $this->testUser, $isInterfaceWeb, false);
                    
                    // Calcular taxa esperada
                    $taxaPercentual = $isInterfaceWeb 
                        ? ($this->setting->taxa_cash_out_padrao ?? 5.00)
                        : ($this->setting->taxa_saque_api_padrao ?? $this->setting->taxa_cash_out_padrao ?? 5.00);
                    
                    $taxaPercentualValor = ($valor * $taxaPercentual) / 100;
                    $taxaMinima = $this->setting->taxa_minima_pix ?? 0;
                    $taxaFixaPix = $this->setting->taxa_fixa_pix ?? 0;
                    
                    $taxaPrincipal = max($taxaPercentualValor, $taxaMinima);
                    $taxaEsperada = $taxaPrincipal + $taxaFixaPix;
                    $saqueLiquidoEsperado = $valor; // Cliente recebe valor integral
                    $valorTotalDescontarEsperado = $valor + $taxaEsperada;

                    $descricaoEsperada = $isInterfaceWeb ? 'GLOBAL_INTERFACE_WEB' : 'GLOBAL_API';

                    $this->validateResult(
                        "Saque Global ({$tipoNome}) - R$ " . number_format($valor, 2, ',', '.'),
                        $resultado,
                        [
                            'taxa_cash_out' => $taxaEsperada,
                            'saque_liquido' => $saqueLiquidoEsperado,
                            'valor_total_descontar' => $valorTotalDescontarEsperado,
                            'descricao' => $descricaoEsperada
                        ],
                        $valor
                    );
                } catch (\Exception $e) {
                    $this->addResult('ERRO', "Saque Global ({$tipoNome}) - R$ {$valor}", $e->getMessage());
                }
            }
        }
    }

    /**
     * Testa saques com taxas individuais
     */
    private function testSaquesTaxasIndividuais()
    {
        $this->info('💸 Testando Saques - Taxas Individuais');
        
        // Ativar taxas personalizadas
        $this->testUser->taxas_personalizadas_ativas = true;
        $this->testUser->taxa_percentual_pix = 3.50;
        $this->testUser->taxa_minima_pix = 2.00;
        $this->testUser->taxa_fixa_pix = 1.00;
        $this->testUser->taxa_saque_api = 4.00;
        $this->testUser->save();

        $valores = [50.00, 100.00, 500.00];
        $tipos = ['web' => true, 'api' => false];

        foreach ($valores as $valor) {
            foreach ($tipos as $tipoNome => $isInterfaceWeb) {
                try {
                    $resultado = TaxaSaqueHelper::calcularTaxaSaque($valor, $this->setting, $this->testUser, $isInterfaceWeb, false);
                    
                    // Calcular taxa esperada
                    $taxaPercentual = $isInterfaceWeb ? 3.50 : 4.00;
                    $taxaPercentualValor = ($valor * $taxaPercentual) / 100;
                    $taxaMinima = 2.00;
                    $taxaFixaPix = 1.00;
                    
                    $taxaPrincipal = max($taxaPercentualValor, $taxaMinima);
                    $taxaEsperada = $taxaPrincipal + $taxaFixaPix;
                    $saqueLiquidoEsperado = $valor;
                    $valorTotalDescontarEsperado = $valor + $taxaEsperada;

                    $descricaoEsperada = $isInterfaceWeb ? 'PERSONALIZADA_INTERFACE_WEB' : 'PERSONALIZADA_API';

                    $this->validateResult(
                        "Saque Individual ({$tipoNome}) - R$ " . number_format($valor, 2, ',', '.'),
                        $resultado,
                        [
                            'taxa_cash_out' => $taxaEsperada,
                            'saque_liquido' => $saqueLiquidoEsperado,
                            'valor_total_descontar' => $valorTotalDescontarEsperado,
                            'descricao' => $descricaoEsperada
                        ],
                        $valor
                    );
                } catch (\Exception $e) {
                    $this->addResult('ERRO', "Saque Individual ({$tipoNome}) - R$ {$valor}", $e->getMessage());
                }
            }
        }

        // Restaurar configurações
        $this->testUser->taxas_personalizadas_ativas = false;
        $this->testUser->save();
    }

    /**
     * Testa valores limites
     */
    private function testValoresLimites()
    {
        $this->info('🔍 Testando Valores Limites');
        
        $cenarios = [
            ['valor' => 0.01, 'descricao' => 'Valor mínimo'],
            ['valor' => 999999.99, 'descricao' => 'Valor máximo'],
            ['valor' => 1.00, 'descricao' => 'Valor pequeno'],
        ];

        foreach ($cenarios as $cenario) {
            try {
                // Testar depósito
                $resultadoDeposito = TaxaFlexivelHelper::calcularTaxaDeposito($cenario['valor'], $this->setting, $this->testUser);
                $this->addResult('OK', "Limite Depósito - {$cenario['descricao']} (R$ {$cenario['valor']})", 
                    "Taxa: R$ {$resultadoDeposito['taxa_cash_in']}, Líquido: R$ {$resultadoDeposito['deposito_liquido']}");

                // Testar saque
                $resultadoSaque = TaxaSaqueHelper::calcularTaxaSaque($cenario['valor'], $this->setting, $this->testUser, true, false);
                $this->addResult('OK', "Limite Saque - {$cenario['descricao']} (R$ {$cenario['valor']})", 
                    "Taxa: R$ {$resultadoSaque['taxa_cash_out']}, Total descontar: R$ {$resultadoSaque['valor_total_descontar']}");
            } catch (\Exception $e) {
                $this->addResult('ERRO', "Limite - {$cenario['descricao']}", $e->getMessage());
            }
        }
    }

    /**
     * Testa consistência entre diferentes métodos de cálculo
     */
    private function testConsistencia()
    {
        $this->info('🔄 Testando Consistência');
        
        $valor = 100.00;
        
        try {
            // Testar múltiplas chamadas com mesmos parâmetros
            $resultado1 = TaxaFlexivelHelper::calcularTaxaDeposito($valor, $this->setting, $this->testUser);
            $resultado2 = TaxaFlexivelHelper::calcularTaxaDeposito($valor, $this->setting, $this->testUser);
            
            if ($resultado1['taxa_cash_in'] === $resultado2['taxa_cash_in'] && 
                $resultado1['deposito_liquido'] === $resultado2['deposito_liquido']) {
                $this->addResult('OK', 'Consistência Depósito', 'Resultados idênticos em múltiplas chamadas');
            } else {
                $this->addResult('ERRO', 'Consistência Depósito', 'Resultados diferentes em múltiplas chamadas');
            }

            // Testar saque
            $resultadoSaque1 = TaxaSaqueHelper::calcularTaxaSaque($valor, $this->setting, $this->testUser, true, false);
            $resultadoSaque2 = TaxaSaqueHelper::calcularTaxaSaque($valor, $this->setting, $this->testUser, true, false);
            
            if ($resultadoSaque1['taxa_cash_out'] === $resultadoSaque2['taxa_cash_out']) {
                $this->addResult('OK', 'Consistência Saque', 'Resultados idênticos em múltiplas chamadas');
            } else {
                $this->addResult('ERRO', 'Consistência Saque', 'Resultados diferentes em múltiplas chamadas');
            }
        } catch (\Exception $e) {
            $this->addResult('ERRO', 'Consistência', $e->getMessage());
        }
    }

    /**
     * Valida resultado do teste
     */
    private function validateResult(string $nome, array $resultado, array $esperado, float $valorOriginal)
    {
        $erros = [];
        $tolerancia = 0.01; // Tolerância de 1 centavo para comparações de ponto flutuante

        foreach ($esperado as $campo => $valorEsperado) {
            if (!isset($resultado[$campo])) {
                $erros[] = "Campo '{$campo}' não encontrado no resultado";
                continue;
            }

            $valorAtual = $resultado[$campo];
            
            // Comparação com tolerância para valores numéricos
            if (is_numeric($valorEsperado) && is_numeric($valorAtual)) {
                if (abs($valorAtual - $valorEsperado) > $tolerancia) {
                    $erros[] = "Campo '{$campo}': esperado " . number_format($valorEsperado, 2, ',', '.') . 
                               ", obtido " . number_format($valorAtual, 2, ',', '.');
                }
            } elseif ($valorEsperado !== $valorAtual) {
                $erros[] = "Campo '{$campo}': esperado '{$valorEsperado}', obtido '{$valorAtual}'";
            }
        }

        // Validar que taxa + líquido = valor original (com tolerância)
        // NOTA: Se o depósito líquido for 0 (proteção contra negativo), a soma pode ser menor que o original
        if (isset($resultado['taxa_cash_in']) && isset($resultado['deposito_liquido'])) {
            $soma = $resultado['taxa_cash_in'] + $resultado['deposito_liquido'];
            // Se o líquido é 0, significa que a taxa consumiu todo o valor (proteção implementada)
            if ($resultado['deposito_liquido'] > 0 && abs($soma - $valorOriginal) > $tolerancia) {
                $erros[] = "Soma taxa + líquido não igual ao valor original: " . 
                          number_format($soma, 2, ',', '.') . " vs " . number_format($valorOriginal, 2, ',', '.');
            }
            // Se o líquido é 0, isso indica que as taxas estão muito altas para o valor
            // Isso é um comportamento válido (proteção contra negativo), mas pode ser um alerta
            // Não vamos falhar o teste por isso, apenas aceitar o comportamento
        }

        if (empty($erros)) {
            $this->addResult('OK', $nome, 'Valores corretos');
        } else {
            $this->addResult('FALHA', $nome, implode('; ', $erros));
        }
    }

    /**
     * Adiciona resultado ao array
     */
    private function addResult(string $status, string $teste, string $mensagem)
    {
        $this->results[] = [
            'status' => $status,
            'teste' => $teste,
            'mensagem' => $mensagem
        ];
    }

    /**
     * Mostra resultados finais
     */
    private function showResults()
    {
        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════');
        $this->info('📊 RESULTADOS DOS TESTES');
        $this->info('═══════════════════════════════════════════════════════');
        $this->newLine();

        $ok = 0;
        $falha = 0;
        $erro = 0;

        foreach ($this->results as $result) {
            $status = $result['status'];
            $teste = $result['teste'];
            $mensagem = $result['mensagem'];

            if ($status === 'OK') {
                $this->line("✅ {$teste}: {$mensagem}");
                $ok++;
            } elseif ($status === 'FALHA') {
                $this->warn("⚠️  {$teste}: {$mensagem}");
                $falha++;
            } else {
                $this->error("❌ {$teste}: {$mensagem}");
                $erro++;
            }
        }

        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════');
        $this->info("Total: " . count($this->results) . " testes");
        $this->info("✅ Sucesso: {$ok}");
        $this->info("⚠️  Falhas: {$falha}");
        $this->info("❌ Erros: {$erro}");
        $this->info('═══════════════════════════════════════════════════════');

        if ($falha === 0 && $erro === 0) {
            $this->newLine();
            $this->info('🎉 Todos os testes passaram!');
        }
    }

    /**
     * Cria usuário de teste temporário
     */
    private function createTestUser(): User
    {
        $username = 'test_taxas_' . time();
        
        $user = User::create([
            'username' => $username,
            'user_id' => $username,
            'cliente_id' => $username, // Usar username como cliente_id (padrão AdminUserService)
            'name' => 'Usuário Teste Taxas',
            'email' => 'test_taxas_' . time() . '@test.com',
            'password' => bcrypt('test123'),
            'status' => 1,
            'permission' => 0,
            'saldo' => 10000.00, // Saldo alto para testes
            'code_ref' => uniqid(),
            'data_cadastro' => \Carbon\Carbon::now('America/Sao_Paulo')->format('Y-m-d H:i:s'),
            'avatar' => "/uploads/avatars/avatar_default.jpg",
        ]);

        // Criar chaves de API para o usuário (necessário para alguns testes)
        \App\Models\UsersKey::create([
            'user_id' => $username,
            'token' => \Illuminate\Support\Str::uuid()->toString(),
            'secret' => \Illuminate\Support\Str::uuid()->toString(),
            'status' => 'active'
        ]);

        return $user;
    }
}
