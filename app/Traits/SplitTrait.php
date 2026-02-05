<?php

namespace App\Traits;

use App\Models\SplitPayment;
use App\Models\SplitInterno;
use App\Models\SplitInternoExecutado;
use App\Models\Solicitacoes;
use App\Models\User;
use App\Helpers\Helper;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

trait SplitTrait
{
    /**
     * Processa splits para uma transação
     */
    public static function processSplits(Solicitacoes $solicitacao, User $user): array
    {
        try {
            Log::info('=== INÍCIO DO PROCESSAMENTO DE SPLIT ===', [
                'solicitacao_id' => $solicitacao->id,
                'idTransaction' => $solicitacao->idTransaction,
                'valor_bruto' => $solicitacao->amount,
                'valor_liquido' => $solicitacao->deposito_liquido,
                'split_email' => $solicitacao->split_email,
                'split_percentage' => $solicitacao->split_percentage,
                'user_id_original' => $user->user_id
            ]);
            
            // Verificar se já existem splits processados para esta transação
            $existingSplits = SplitPayment::where('solicitacao_id', $solicitacao->id)
                ->whereIn('split_status', [SplitPayment::STATUS_COMPLETED, SplitPayment::STATUS_PROCESSING])
                ->count();
            
            if ($existingSplits > 0) {
                Log::info('[SPLIT] Splits já processados para esta transação', [
                    'solicitacao_id' => $solicitacao->id,
                    'existing_splits' => $existingSplits
                ]);
                return [['status' => 'skipped', 'message' => 'Splits já processados para esta transação']];
            }
            
            $splits = [];
            
            // Verificar se há splits configurados na transação
            if ($solicitacao->split_email && $solicitacao->split_percentage) {
                Log::info('[SPLIT] Criando split da transação', [
                    'split_email' => $solicitacao->split_email,
                    'split_percentage' => $solicitacao->split_percentage,
                    'valor_bruto' => $solicitacao->amount,
                    'valor_liquido' => $solicitacao->deposito_liquido
                ]);
                $splits[] = self::createSplitFromSolicitacao($solicitacao, $user);
            }
            
            // Verificar se o usuário tem splits automáticos configurados
            $userSplits = self::getUserSplits($user);
            foreach ($userSplits as $splitConfig) {
                $splits[] = self::createSplitFromConfig($solicitacao, $user, $splitConfig);
            }
            
            // Processar splits internos
            $resultadosSplitsInternos = self::processarSplitsInternos($solicitacao, $user);
            
            // Processar todos os splits externos
            Log::info('[SPLIT] Iniciando processamento de splits', [
                'total_splits' => count($splits)
            ]);
            
            $results = [];
            foreach ($splits as $split) {
                Log::info('[SPLIT] Executando split individual', [
                    'split_id' => $split->id,
                    'split_amount' => $split->split_amount,
                    'split_email' => $split->split_email
                ]);
                $result = self::executeSplit($split, $solicitacao);
                $results[] = $result;
            }
            
            Log::info('[SPLIT] Splits processados com sucesso', [
                'solicitacao_id' => $solicitacao->id,
                'user_id' => $user->user_id,
                'splits_count' => count($splits),
                'splits_internos_count' => count($resultadosSplitsInternos),
                'results' => array_merge($results, $resultadosSplitsInternos)
            ]);
            
            Log::info('=== FIM DO PROCESSAMENTO DE SPLIT ===', [
                'solicitacao_id' => $solicitacao->id,
                'status' => 'concluido'
            ]);
            
            return array_merge($results, $resultadosSplitsInternos);
            
        } catch (\Exception $e) {
            Log::error('[SPLIT] Erro ao processar splits', [
                'solicitacao_id' => $solicitacao->id,
                'user_id' => $user->user_id,
                'error' => $e->getMessage()
            ]);
            
            return [];
        }
    }
    
    /**
     * Cria split baseado nos dados da solicitação
     */
    private static function createSplitFromSolicitacao(Solicitacoes $solicitacao, User $user): SplitPayment
    {
        // Calcular split sobre o valor líquido, não o valor bruto
        $splitAmount = ($solicitacao->deposito_liquido * $solicitacao->split_percentage) / 100;
        
        Log::info('[SPLIT] Calculando valor do split', [
            'valor_liquido' => $solicitacao->deposito_liquido,
            'split_percentage' => $solicitacao->split_percentage,
            'split_amount_calculado' => $splitAmount,
            'formula' => "({$solicitacao->deposito_liquido} * {$solicitacao->split_percentage}) / 100"
        ]);
        
        $split = SplitPayment::create([
            'solicitacao_id' => $solicitacao->id,
            'user_id' => $user->user_id,
            'split_email' => $solicitacao->split_email,
            'split_percentage' => $solicitacao->split_percentage,
            'split_amount' => $splitAmount,
            'split_status' => SplitPayment::STATUS_PENDING,
            'split_type' => SplitPayment::TYPE_PERCENTAGE,
            'description' => "Split de {$solicitacao->split_percentage}% para {$solicitacao->split_email}"
        ]);
        
        Log::info('[SPLIT] Split criado no banco de dados', [
            'split_id' => $split->id,
            'split_amount' => $split->split_amount,
            'split_email' => $split->split_email
        ]);
        
        return $split;
    }
    
    /**
     * Cria split baseado na configuração do usuário
     */
    private static function createSplitFromConfig(Solicitacoes $solicitacao, User $user, array $config): SplitPayment
    {
        // Calcular split sobre o valor líquido, não o valor bruto
        $splitAmount = ($solicitacao->deposito_liquido * $config['percentage']) / 100;
        
        return SplitPayment::create([
            'solicitacao_id' => $solicitacao->id,
            'user_id' => $user->user_id,
            'split_email' => $config['email'],
            'split_percentage' => $config['percentage'],
            'split_amount' => $splitAmount,
            'split_status' => SplitPayment::STATUS_PENDING,
            'split_type' => $config['type'] ?? SplitPayment::TYPE_PERCENTAGE,
            'description' => $config['description'] ?? "Split automático de {$config['percentage']}%"
        ]);
    }
    
    /**
     * Obtém splits configurados para o usuário
     */
    private static function getUserSplits(User $user): array
    {
        // Aqui você pode implementar lógica para buscar splits automáticos
        // Por exemplo, de uma tabela de configurações de split por usuário
        // Por enquanto, retornamos array vazio
        return [];
    }
    
    /**
     * Executa um split específico
     */
    private static function executeSplit(SplitPayment $split, Solicitacoes $solicitacao): array
    {
        try {
            $split->markAsProcessing();
            
            // Verificar se o valor do split é válido
            if ($split->split_amount <= 0) {
                $split->markAsFailed('Valor do split inválido');
                return ['status' => 'failed', 'message' => 'Valor do split inválido'];
            }
            
            // Verificar se há saldo suficiente
            if ($solicitacao->deposito_liquido < $split->split_amount) {
                $split->markAsFailed('Saldo insuficiente para split');
                return ['status' => 'failed', 'message' => 'Saldo insuficiente'];
            }
            
            // Buscar usuário destinatário do split
            $splitUser = User::where('email', $split->split_email)->first();
            
            if (!$splitUser) {
                // Se o usuário não existe, criar um registro pendente
                $split->markAsFailed('Usuário destinatário não encontrado');
                return ['status' => 'failed', 'message' => 'Usuário não encontrado'];
            }
            
            // Executar o split
            $result = self::transferSplitAmount($split, $splitUser, $solicitacao);
            
            if ($result['success']) {
                $split->markAsCompleted();
                return ['status' => 'completed', 'message' => 'Split executado com sucesso'];
            } else {
                $split->markAsFailed($result['message']);
                return ['status' => 'failed', 'message' => $result['message']];
            }
            
        } catch (\Exception $e) {
            $split->markAsFailed($e->getMessage());
            Log::error('[SPLIT] Erro ao executar split', [
                'split_id' => $split->id,
                'error' => $e->getMessage()
            ]);
            
            return ['status' => 'failed', 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Transfere o valor do split para o usuário destinatário
     */
    private static function transferSplitAmount(SplitPayment $split, User $splitUser, Solicitacoes $solicitacao): array
    {
        try {
            Log::info('[SPLIT] Iniciando transferência de valores', [
                'split_id' => $split->id,
                'split_amount' => $split->split_amount,
                'from_user' => $solicitacao->user_id,
                'to_user' => $splitUser->user_id
            ]);
            
            // Buscar usuário original
            $originalUser = User::where('user_id', $solicitacao->user_id)->first();
            
            Log::info('[SPLIT] Saldos ANTES da transferência', [
                'usuario_original' => $originalUser ? $originalUser->saldo : 'N/A',
                'usuario_split' => $splitUser->saldo
            ]);
            
            // Creditar o valor para o usuário destinatário
            Helper::incrementAmount($splitUser, $split->split_amount, 'saldo');
            Helper::calculaSaldoLiquido($splitUser->user_id);
            
            Log::info('[SPLIT] Valor creditado para usuário de split', [
                'user_id' => $splitUser->user_id,
                'amount' => $split->split_amount,
                'novo_saldo' => $splitUser->fresh()->saldo
            ]);
            
            // Debitar o valor do usuário original
            if ($originalUser) {
                Helper::decrementAmount($originalUser, $split->split_amount, 'saldo');
                Helper::calculaSaldoLiquido($originalUser->user_id);
                
                Log::info('[SPLIT] Valor debitado do usuário original', [
                    'user_id' => $originalUser->user_id,
                    'amount' => $split->split_amount,
                    'novo_saldo' => $originalUser->fresh()->saldo
                ]);
            }
            
            // Log da transação de split (sem criar registro na tabela transactions por enquanto)
            Log::info('[SPLIT] Transação de split registrada', [
                'split_id' => $split->id,
                'user_id' => $splitUser->user_id,
                'amount' => $split->split_amount,
                'reference' => "split_{$split->id}"
            ]);
            
            Log::info('[SPLIT] Saldos APÓS a transferência', [
                'usuario_original' => $originalUser ? $originalUser->fresh()->saldo : 'N/A',
                'usuario_split' => $splitUser->fresh()->saldo
            ]);
            
            Log::info('[SPLIT] Split transferido com sucesso', [
                'split_id' => $split->id,
                'from_user' => $solicitacao->user_id,
                'to_user' => $splitUser->user_id,
                'amount' => $split->split_amount
            ]);
            
            return ['success' => true, 'message' => 'Split transferido com sucesso'];
            
        } catch (\Exception $e) {
            Log::error('[SPLIT] Erro ao transferir split', [
                'split_id' => $split->id,
                'error' => $e->getMessage()
            ]);
            
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Obtém estatísticas de splits
     */
    public static function getSplitStats(User $user = null, $startDate = null, $endDate = null): array
    {
        $query = SplitPayment::query();
        
        if ($user) {
            $query->where('user_id', $user->user_id);
        }
        
        if ($startDate && $endDate) {
            $query->whereBetween('created_at', [$startDate, $endDate]);
        }
        
        $stats = [
            'total_splits' => $query->count(),
            'pending_splits' => $query->where('split_status', SplitPayment::STATUS_PENDING)->count(),
            'completed_splits' => $query->where('split_status', SplitPayment::STATUS_COMPLETED)->count(),
            'failed_splits' => $query->where('split_status', SplitPayment::STATUS_FAILED)->count(),
            'total_amount' => $query->where('split_status', SplitPayment::STATUS_COMPLETED)->sum('split_amount'),
            'pending_amount' => $query->where('split_status', SplitPayment::STATUS_PENDING)->sum('split_amount'),
        ];
        
        return $stats;
    }
    
    /**
     * Processa splits pendentes em lote
     */
    public static function processPendingSplits(): array
    {
        $pendingSplits = SplitPayment::pending()->with(['solicitacao', 'user'])->get();
        $results = [];
        
        foreach ($pendingSplits as $split) {
            $result = self::executeSplit($split, $split->solicitacao);
            $results[] = [
                'split_id' => $split->id,
                'result' => $result
            ];
        }
        
        Log::info('[SPLIT] Processamento em lote concluído', [
            'processed_count' => count($pendingSplits),
            'results' => $results
        ]);
        
        return $results;
    }
    
    /**
     * Cancela um split
     */
    public static function cancelSplit(SplitPayment $split, string $reason = null): bool
    {
        try {
            if ($split->isCompleted()) {
                return false; // Não pode cancelar split já processado
            }
            
            $split->update([
                'split_status' => SplitPayment::STATUS_CANCELLED,
                'error_message' => $reason ?? 'Split cancelado pelo usuário',
                'processed_at' => now()
            ]);
            
            Log::info('[SPLIT] Split cancelado', [
                'split_id' => $split->id,
                'reason' => $reason
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('[SPLIT] Erro ao cancelar split', [
                'split_id' => $split->id,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    // ========================================================================
    // MÉTODOS PARA SPLITS INTERNOS
    // ========================================================================
    
    /**
     * Processa splits internos para uma transação
     */
    public static function processarSplitsInternos(Solicitacoes $solicitacao, User $user): array
    {
        try {
            Log::info('=== PROCESSAMENTO DE SPLITS INTERNOS INICIADO ===', [
                'solicitacao_id' => $solicitacao->id,
                'user_id' => $user->id,
                'tipo_transacao' => $solicitacao->tipo ?? 'deposito'
            ]);

            $resultados = [];
            
            // Determinar tipo de taxa baseado na transação
            $tipoTaxa = self::determinarTipoTaxa($solicitacao);
            
            // PROCESSAMENTO AUTOMÁTICO DE SPLIT DE GERENTE
            // Se o usuário tem um gerente cadastrado, criar split automaticamente
            $splitGerenteResultado = self::processarSplitAutomaticoGerente($solicitacao, $user);
            if ($splitGerenteResultado) {
                $resultados[] = $splitGerenteResultado;
            }

            // PROCESSAMENTO AUTOMÁTICO DE SPLIT DE AFFILIATE
            // Se o usuário foi indicado por um affiliado ativo, criar split automaticamente
            $splitAffiliateResultado = self::processarSplitAutomaticoAffiliate($solicitacao, $user);
            if ($splitAffiliateResultado) {
                $resultados[] = $splitAffiliateResultado;
            }

            // Buscar configurações de split interno válidas para este usuário
            $configuracoesSplit = SplitInterno::obterConfiguracoesParaUsuario($user->id, $tipoTaxa);
            
            if (empty($configuracoesSplit)) {
                Log::info('[SPLIT INTERNO] Nenhuma configuração de split interno encontrada', [
                    'user_id' => $user->id,
                    'tipo_taxa' => $tipoTaxa
                ]);
                return $resultados;
            }

            Log::info('[SPLIT INTERNO] Configurações encontradas', [
                'count' => count($configuracoesSplit),
                'configuracoes' => $configuracoesSplit
            ]);

            // Calcular valor da TAXA PERCENTUAL apenas (exclui taxas fixas)
            $valorTaxaPercentual = self::calcularTaxaPercentualParaSplit($solicitacao, $user);
            
            Log::info('[SPLIT INTERNO] Valor da taxa percentual para split', [
                'valor_taxa_percentual' => $valorTaxaPercentual,
                'observacao' => 'Split será aplicado sobre este valor em sua PORCENTAGEM CONFIGURADA (exclui taxas fixas)'
            ]);
            
            // Processar cada configuração
            foreach ($configuracoesSplit as $configuracao) {
                $resultado = self::executarSplitInterno($solicitacao, $user, $configuracao, $valorTaxaPercentual);
                $resultados[] = $resultado;
            }

            Log::info('=== PROCESSAMENTO DE SPLITS INTERNOS FINALIZADO ===', [
                'total_processados' => count($resultados),
                'resultados' => $resultados
            ]);

            return $resultados;

        } catch (\Exception $e) {
            Log::error('[SPLIT INTERNO] Erro ao processar splits internos', [
                'solicitacao_id' => $solicitacao->id,
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            
            return [];
        }
    }

    /**
     * Executa um split interno específico
     * IMPORTANTE: Split só se aplica sobre a TAXA PERCENTUAL, nunca sobre taxas fixas
     */
    private static function executarSplitInterno(Solicitacoes $solicitacao, User $userPagador, array $configuracao, float $valorTaxaPercentual): array
    {
        try {
            $valorSplit = $configuracao['porcentagem_split'] * ($valorTaxaPercentual / 100);
            
            Log::info('[SPLIT INTERNO] Executando split interno', [
                'solicitacao_id' => $solicitacao->id,
                'configuracao_id' => $configuracao['id'],
                'usuario_pagador' => $userPagador->id,
                'usuario_beneficiario' => $configuracao['usuario_beneficiario_id'],
                'porcentagem_split' => $configuracao['porcentagem_split'] . '%',
                'valor_taxa_percentual' => 'R$ ' . number_format($valorTaxaPercentual, 2),
                'valor_split_calculado' => 'R$ ' . number_format($valorSplit, 2),
                'observacao' => 'Split aplicado APENAS sobre taxa percentual - taxas fixas não sofrem split'
            ]);

            // Criar registro do split executado
            $splitExecutado = SplitInternoExecutado::criarSplit(
                $configuracao['id'],
                $solicitacao->id,
                [
                    'usuario_pagador_id' => $userPagador->id,
                    'usuario_beneficiario_id' => $configuracao['usuario_beneficiario_id'],
                    'valor_taxa_original' => $valorTaxaPercentual,
                    'valor_split' => $valorSplit,
                    'porcentagem_aplicada' => $configuracao['porcentagem_split'],
                    'observacoes' => "Split interno de {$configuracao['porcentagem_split']}% APENAS sobre taxa percentual de {$configuracao['tipo_taxa']} (não inclui taxas fixas)"
                ]
            );

            // Validar se existe taxa percentual suficiente
            if ($valorSplit > $valorTaxaPercentual) {
                $splitExecutado->marcarComoFalhado('Valor do split maior que a taxa percentual disponível');
                return [
                    'status' => 'failed',
                    'split_id' => $splitExecutado->id,
                    'message' => 'Valor do split maior que a taxa percentual disponível'
                ];
            }

            // Buscar usuário beneficiário
            $usuarioBeneficiario = User::find($configuracao['usuario_beneficiario_id']);
            if (!$usuarioBeneficiario) {
                $splitExecutado->marcarComoFalhado('Usuário beneficiário não encontrado');
                return [
                    'status' => 'failed',
                    'split_id' => $splitExecutado->id,
                    'message' => 'Usuário beneficiário não encontrado'
                ];
            }

            // Executar transferência via split interno
            $resultadoTransferencia = self::executarTransferenciaSplitInterno(
                $splitExecutado,
                $usuarioBeneficiario,
                $valorTaxaPercentual,
                $valorSplit
            );

            if ($resultadoTransferencia['success']) {
                $splitExecutado->marcarComoProcessado('Split interno executado com sucesso');
                return [
                    'status' => 'completed',
                    'split_id' => $splitExecutado->id,
                    'valor_split' => $valorSplit,
                    'usuario_beneficiario' => $usuarioBeneficiario->name,
                    'message' => 'Split interno executado com sucesso'
                ];
            } else {
                $splitExecutado->marcarComoFalhado($resultadoTransferencia['message']);
                return [
                    'status' => 'failed',
                    'split_id' => $splitExecutado->id,
                    'message' => $resultadoTransferencia['message']
                ];
            }

        } catch (\Exception $e) {
            Log::error('[SPLIT INTERNO] Erro ao executar split específico', [
                'configuracao_id' => $configuracao['id'] ?? 'N/A',
                'error' => $e->getMessage()
            ]);
            
            return [
                'status' => 'failed',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Executa a transferência de valores do split interno
     */
    private static function executarTransferenciaSplitInterno(
        SplitInternoExecutado $splitExecutado,
        User $usuarioBeneficiario,
        float $valorTaxaTotal,
        float $valorSplit
    ): array {
        try {
            Log::info('[SPLIT INTERNO] Iniciando transferência de valores', [
                'split_executado_id' => $splitExecutado->id,
                'usuario_beneficiario_id' => $usuarioBeneficiario->id,
                'valor_split' => $valorSplit
            ]);

            // Creditar o valor para o usuário beneficiário
            Helper::incrementAmount($usuarioBeneficiario, $valorSplit, 'saldo');
            Helper::calculaSaldoLiquido($usuarioBeneficiario->user_id);

            Log::info('[SPLIT INTERNO] Valor creditado para beneficiário', [
                'usuario_id' => $usuarioBeneficiario->id,
                'valor_creditado' => $valorSplit,
                'novo_saldo' => $usuarioBeneficiario->fresh()->saldo
            ]);

            return ['success' => true, 'message' => 'Transferência realizada com sucesso'];

        } catch (\Exception $e) {
            Log::error('[SPLIT INTERNO] Erro na transferência', [
                'split_executado_id' => $splitExecutado->id,
                'error' => $e->getMessage()
            ]);
            
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Determina o tipo de taxa baseado na transação
     */
    private static function determinarTipoTaxa(Solicitacoes $solicitacao): string
    {
        $tipoTransacao = $solicitacao->tipo ?? 'deposito';
        
        if (in_array(strtolower($tipoTransacao), ['saque', 'pix', 'withdrawal'])) {
            return SplitInterno::TAXA_SAQUE_PIX;
        }
        
        return SplitInterno::TAXA_DEPOSITO;
    }

    /**
     * Calcula o valor total da taxa cobrada na transação
     * Funciona independente do tipo de configuração (global, personalizada, flexível)
     */
    private static function calcularValorTaxaTotal(Solicitacoes $solicitacao): float
    {
        Log::info('[SPLIT INTERNO] Calculando valor total da taxa', [
            'solicitacao_id' => $solicitacao->id,
            'amount' => $solicitacao->amount,
            'deposito_liquido' => $solicitacao->deposito_liquido ?? 'N/A',
            'taxa_cash_in' => $solicitacao->taxa_cash_in ?? 'N/A',
            'taxa_cash_out' => $solicitacao->taxa_cash_out ?? 'N/A'
        ]);

        // PRIORIDADE 1: Usar campos específicos de taxa da transação (mais confiável)
        if (isset($solicitacao->taxa_cash_in) && $solicitacao->taxa_cash_in > 0) {
            Log::info('[SPLIT INTERNO] Usando taxa_cash_in', [
                'valor' => $solicitacao->taxa_cash_in
            ]);
            return (float) $solicitacao->taxa_cash_in;
        }

        if (isset($solicitacao->taxa_cash_out) && $solicitacao->taxa_cash_out > 0) {
            Log::info('[SPLIT INTERNO] Usando taxa_cash_out', [
                'valor' => $solicitacao->taxa_cash_out
            ]);
            return (float) $solicitacao->taxa_cash_out;
        }

        // PRIORIDADE 2: Calcular diferença entre valor bruto e líquido (método alternativo)
        $valorBruto = (float) $solicitacao->amount;
        $valorLiquido = (float) ($solicitacao->deposito_liquido ?? $solicitacao->amount);
        $taxaCalculada = $valorBruto - $valorLiquido;
        
        Log::info('[SPLIT INTERNO] Taxa calculada por diferença', [
            'valor_bruto' => $valorBruto,
            'valor_liquido' => $valorLiquido,
            'taxa_calculada' => $taxaCalculada
        ]);
        
        // VALIDAÇÃO: A taxa deve ser positiva
        if ($taxaCalculada <= 0) {
            Log::warning('[SPLIT INTERNO] Taxa calculada é zero ou negativa', [
                'taxa_calculada' => $taxaCalculada,
                'valor_bruto' => $valorBruto,
                'valor_liquido' => $valorLiquido
            ]);
            return 0;
        }
        
        return $taxaCalculada;
    }

    /**
     * Calcula APENAS a parte percentual da taxa (exclui taxas fixas)
     * IMPORTANTE: O split interno só se aplica sobre a taxa percentual, nunca sobre taxas fixas
     */
    private static function calcularTaxaPercentualParaSplit(Solicitacoes $solicitacao, User $userPagador): float
    {
        try {
            $valorDeposito = (float) $solicitacao->amount;
            
            Log::info('[SPLIT INTERNO] Calculando taxa percentual para split', [
                'solicitacao_id' => $solicitacao->id,
                'valor_deposito' => $valorDeposito,
                'user_id' => $userPagador->id,
                'user_taxas_personalizadas' => $userPagador->taxas_personalizadas_ativas ?? false
            ]);

            // Verificar se o usuário tem taxas personalizadas
            if ($userPagador->taxas_personalizadas_ativas) {
                
                // Sistema flexível ativo
                if ($userPagador->sistema_flexivel_ativo) {
                    $valorMinimoFlexivel = $userPagador->valor_minimo_flexivel ?? 15.00;
                    
                    if ($valorDeposito >= $valorMinimoFlexivel) {
                        // Valor alto: usar taxa percentual alta
                        $taxaPercentual = $userPagador->taxa_percentual_altos ?? 4.00;
                        $taxaPercentualValor = ($valorDeposito * $taxaPercentual) / 100;
                    } else {
                        // Valor baixo: usar taxa fixa (não aplica split)
                        $taxaFixaBaixo = $userPagador->taxa_fixa_baixos ?? 1.00;
                        Log::info('[SPLIT INTERNO] Valor baixo: usando taxa fixa flexível (não aplica split)', [
                            'taxa_fixa_baixo' => $taxaFixaBaixo
                        ]);
                        return 0; // Split não se aplica em taxas fixas
                    }
                } else {
                    // Sistema normal: taxas percentuais e fixas separadas
                    $taxaPercentual = $userPagador->taxa_percentual_deposito ?? 2.00;
                    $taxaPercentualValor = ($valorDeposito * $taxaPercentual) / 100;
                    
                    // Verificar taxa mínima (se for menor, usar fixa mínima)
                    $taxaMinima = $userPagador->valor_minimo_deposito ?? 5.00;
                    if ($taxaPercentualValor < $taxaMinima) {
                        Log::info('[SPLIT INTERNO] Taxa percentual menor que mínima: usando taxa mínima fixa (não aplica split)', [
                            'taxa_percentual_calculada' => $taxaPercentualValor,
                            'taxa_minima' => $taxaMinima
                        ]);
                        return 0; // Split não se aplica em taxas mínimas fixas
                    }
                }
                
            } else {
                // Usar taxas globais
                $taxaPercentual = app(\App\Models\App::class)->first()->taxa_cash_in_padrao ?? 4.00;
                $taxaPercentualValor = ($valorDeposito * $taxaPercentual) / 100;
                
                // Verificar taxa mínima global
                $taxaMinima = app(\App\Models\App::class)->first()->baseline ?? 5.00;
                if ($taxaPercentualValor < $taxaMinima) {
                    Log::info('[SPLIT INTERNO] Taxa global menor que mínima: usando taxa mínima fixa (não aplica split)', [
                        'taxa_percentual_calculada' => $taxaPercentualValor,
                        'taxa_minima' => $taxaMinima
                    ]);
                    return 0; // Split não se aplica em taxas mínimas fixas
                }
            }
            
            Log::info('[SPLIT INTERNO] Taxa percentual calculada para split', [
                'taxa_percentual_valor' => $taxaPercentualValor,
                'user_id' => $userPagador->id
            ]);
            
            return $taxaPercentualValor;
            
        } catch (\Exception $e) {
            Log::error('[SPLIT INTERNO] Erro ao calcular taxa percentual para split', [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Obtém estatísticas de splits internos para um usuário
     */
    public static function obterEstatisticasSplitInterno(User $usuario): array
    {
        $splitsRecebidos = SplitInternoExecutado::porBeneficiario($usuario->id)
            ->processados()
            ->get();

        $splitsPagos = SplitInternoExecutado::porPagador($usuario->id)
            ->processados()
            ->get();

        return [
            'total_recebido' => $splitsRecebidos->sum('valor_split'),
            'total_pago' => $splitsPagos->sum('valor_split'),
            'quantidade_recebidos' => $splitsRecebidos->count(),
            'quantidade_pagos' => $splitsPagos->count(),
            'detalhes_recebidos' => $splitsRecebidos,
            'detalhes_pagos' => $splitsPagos
        ];
    }

    /**
     * Processa automaticamente split de gerente se configurado
     * 
     * @param Solicitacoes $solicitacao
     * @param User $user
     * @return array|null
     */
    private static function processarSplitAutomaticoGerente(Solicitacoes $solicitacao, User $user): ?array
    {
        try {
            // Verificar se o usuário tem gerente cadastrado
            if (!$user->gerente_id || !$user->gerente_percentage) {
                Log::info('[SPLIT GERENTE] Usuário sem gerente ou porcentagem configurada', [
                    'user_id' => $user->id,
                    'gerente_id' => $user->gerente_id,
                    'gerente_percentage' => $user->gerente_percentage
                ]);
                return null;
            }

            // Buscar o gerente
            $gerente = User::find($user->gerente_id);
            if (!$gerente) {
                Log::warning('[SPLIT GERENTE] Gerente não encontrado', [
                    'user_id' => $user->id,
                    'gerente_id' => $user->gerente_id
                ]);
                return null;
            }

            // Verificar se já existe split automático configurado para este gerente
            $splitExistente = SplitInterno::query()
                ->where('usuario_pagador_id', $user->id)
                ->where('usuario_beneficiario_id', $gerente->id)
                ->ativo()
                ->first();

            if ($splitExistente) {
                Log::info('[SPLIT GERENTE] Split já configurado para este gerente', [
                    'split_id' => $splitExistente->id,
                    'user_id' => $user->id,
                    'gerente_id' => $gerente->id
                ]);
                return null;
            }

            // Criar configuração de split automática para o gerente
            $novoSplit = SplitInterno::create([
                'usuario_pagador_id' => $user->id,
                'usuario_beneficiario_id' => $gerente->id,
                'porcentagem_split' => $user->gerente_percentage,
                'tipo_taxa' => SplitInterno::TAXA_DEPOSITO,
                'ativo' => true,
                'criado_por_admin_id' => 1, // Sistema automático
                'data_inicio' => now(),
                'data_fim' => null,
            ]);

            Log::info('[SPLIT GERENTE] Split automático configurado', [
                'split_id' => $novoSplit->id,
                'user_id' => $user->id,
                'gerente_id' => $gerente->id,
                'gerente_percentage' => $user->gerente_percentage,
                'split_percentage' => $novoSplit->porcentagem_split
            ]);

            // Calcular valor da taxa percentual para execução
            $valorTaxaPercentual = self::calcularTaxaPercentualParaSplit($solicitacao, $user);
            
            // Executar o split agora
            $resultado = self::executarSplitInterno($solicitacao, $user, $novoSplit->toArray(), $valorTaxaPercentual);
            
            Log::info('[SPLIT GERENTE] Split executado automaticamente', [
                'split_id' => $novoSplit->id,
                'valor_split' => $resultado['valor_split'] ?? 0,
                'user_id' => $user->id,
                'gerente_id' => $gerente->id
            ]);

            return $resultado;

        } catch (\Exception $e) {
            Log::error('[SPLIT GERENTE] Erro ao processar split automático', [
                'user_id' => $user->id,
                'gerente_id' => $user->gerente_id,
                'error' => $e->getMessage()
            ]);
            
            return null;
        }
    }

    /**
     * Processa automaticamente split de affiliate se configurado
     * 
     * @param Solicitacoes $solicitacao
     * @param User $user
     * @return array|null
     */
    private static function processarSplitAutomaticoAffiliate(Solicitacoes $solicitacao, User $user): ?array
    {
        try {
            // Verificar se o usuário foi indicado por um affiliate ativo
            if (!$user->affiliate_id || !$user->affiliate_percentage) {
                Log::info('[SPLIT AFFILIATE] Usuário sem affiliate ou porcentagem configurada', [
                    'user_id' => $user->id,
                    'affiliate_id' => $user->affiliate_id,
                    'affiliate_percentage' => $user->affiliate_percentage
                ]);
                return null;
            }

            // Buscar o affiliate
            $affiliate = User::find($user->affiliate_id);
            if (!$affiliate || !$affiliate->isAffiliateAtivo()) {
                Log::warning('[SPLIT AFFILIATE] Affiliate não encontrado ou inativo', [
                    'user_id' => $user->id,
                    'affiliate_id' => $user->affiliate_id
                ]);
                return null;
            }

            // Verificar se já existe split automático configurado para este affiliate
            $splitExistente = SplitInterno::query()
                ->where('usuario_pagador_id', $user->id)
                ->where('usuario_beneficiario_id', $affiliate->id)
                ->ativo()
                ->first();

            if ($splitExistente) {
                Log::info('[SPLIT AFFILIATE] Split já configurado para este affiliate', [
                    'split_id' => $splitExistente->id,
                    'user_id' => $user->id,
                    'affiliate_id' => $affiliate->id
                ]);
                return null;
            }

            // Criar configuração de split automática para_o affiliate
            $novoSplit = SplitInterno::create([
                'usuario_pagador_id' => $user->id,
                'usuario_beneficiario_id' => $affiliate->id,
                'porcentagem_split' => $user->affiliate_percentage,
                'tipo_taxa' => SplitInterno::TAXA_DEPOSITO,
                'ativo' => true,
                'criado_por_admin_id' => 1, // Sistema automático
                'data_inicio' => now(),
                'data_fim' => null,
            ]);

            Log::info('[SPLIT AFFILIATE] Split automático configurado', [
                'split_id' => $novoSplit->id,
                'user_id' => $user->id,
                'affiliate_id' => $affiliate->id,
                'affiliate_percentage' => $user->affiliate_percentage,
                'split_percentage' => $novoSplit->porcentagem_split
            ]);

            // Calcular valor da taxa percentual para execução
            $valorTaxaPercentual = self::calcularTaxaPercentualParaSplit($solicitacao, $user);
            
            // Executar o split agora
            $resultado = self::executarSplitInterno($solicitacao, $user, $novoSplit->toArray(), $valorTaxaPercentual);
            
            Log::info('[SPLIT AFFILIATE] Split executado automaticamente', [
                'split_id' => $novoSplit->id,
                'valor_split' => $resultado['valor_split'] ?? 0,
                'user_id' => $user->id,
                'affiliate_id' => $affiliate->id
            ]);

            return $resultado;

        } catch (\Exception $e) {
            Log::error('[SPLIT AFFILIATE] Erro ao processar split automático', [
                'user_id' => $user->id,
                'affiliate_id' => $user->affiliate_id ?? null,
                'error' => $e->getMessage()
            ]);
            
            return null;
        }
    }
}
