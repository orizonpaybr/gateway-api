<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\CallbackController;
use App\Helpers\Helper;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UnifiedCallbackController extends Controller
{
    /**
     * Callback unificado que redireciona para a adquirente correta
     * baseada na configuração do usuário
     */
    public function handleCallback(Request $request)
    {
        try {
            $data = $request->all();
            Log::info('=== UNIFIED CALLBACK INICIADO ===');
            Log::info('[UNIFIED CALLBACK] Dados recebidos: ' . json_encode($data, JSON_PRETTY_PRINT));

            // Tenta identificar a adquirente pelos dados recebidos
            $adquirente = $this->identifyAdquirente($data);
            
            if (!$adquirente) {
                Log::warning('[UNIFIED CALLBACK] Adquirente não identificada pelos dados');
                return response()->json(['status' => false, 'message' => 'Adquirente não identificada'], 400);
            }

            Log::info("[UNIFIED CALLBACK] Adquirente identificada: $adquirente");

            // Redireciona para o controller da adquirente específica
            // Apenas Pagar.me permanece ativo
            switch ($adquirente) {
                case 'pagarme':
                    // Usar app() para resolver dependências automaticamente
                    $callbackController = app(CallbackController::class);
                    return $callbackController->webhookPagarme($request);
                    
                default:
                    Log::warning("[UNIFIED CALLBACK] Adquirente não suportada ou removida: $adquirente");
                    return response()->json(['status' => false, 'message' => 'Adquirente não suportada'], 400);
            }

        } catch (\Exception $e) {
            Log::error('[UNIFIED CALLBACK] Erro: ' . $e->getMessage());
            Log::error('[UNIFIED CALLBACK] Stack trace: ' . $e->getTraceAsString());
            Log::info('=== FIM UNIFIED CALLBACK (ERRO) ===');
            return response()->json(['status' => false, 'message' => 'Erro interno do servidor'], 500);
        }
    }

    /**
     * Identifica a adquirente baseada nos dados recebidos
     * Nota: Apenas Pagar.me permanece ativo
     */
    private function identifyAdquirente($data)
    {
        Log::info('[UNIFIED CALLBACK] Iniciando identificação da adquirente...');
        
        // Pagar.me - formato específico
        if (isset($data['type']) && isset($data['data']['charges'])) {
            return 'pagarme';
        }
        
        // Tentar identificar pelo external_id ou transaction_id no banco
        $externalId = $data['external_id'] ?? $data['idTransaction'] ?? $data['transaction_id'] ?? 
                      ($data['requestBody']['external_id'] ?? null) ?? null;
        
        if ($externalId) {
            Log::info("[UNIFIED CALLBACK] Buscando transação no banco de dados: $externalId");
            
            // Busca no banco para identificar a adquirente
            $solicitacao = \App\Models\Solicitacoes::where('externalreference', $externalId)
                ->orWhere('idTransaction', $externalId)
                ->first();
                
            if ($solicitacao && $solicitacao->adquirente_ref) {
                Log::info("[UNIFIED CALLBACK] Encontrado em depósitos - Adquirente: {$solicitacao->adquirente_ref}");
                return $solicitacao->adquirente_ref;
            }
            
            // Se não encontrou na tabela de depósitos, busca na tabela de saques
            $cashout = \App\Models\SolicitacoesCashOut::where('externalreference', $externalId)
                ->orWhere('idTransaction', $externalId)
                ->first();
                
            if ($cashout) {
                Log::info("[UNIFIED CALLBACK] Encontrado em saques - Executor: {$cashout->executor_ordem}");
                
                // Se tem executor_ordem definido, usa ele
                if ($cashout->executor_ordem) {
                    Log::info("[UNIFIED CALLBACK] Usando executor_ordem: {$cashout->executor_ordem}");
                    return $cashout->executor_ordem;
                }
                
                // Se não tem executor_ordem, usa a adquirente padrão do sistema
                $adquirentePadrao = \App\Helpers\Helper::adquirenteDefault();
                if ($adquirentePadrao) {
                    Log::info("[UNIFIED CALLBACK] Usando adquirente padrão para saque sem executor_ordem: $adquirentePadrao");
                    return $adquirentePadrao;
                }
            }
            
            Log::warning("[UNIFIED CALLBACK] Transação não encontrada no banco: $externalId");
        }

        return null;
    }

    /**
     * Callback para saques (Cash-out) unificado
     */
    public function handleWithdrawCallback(Request $request)
    {
        try {
            $data = $request->all();
            Log::debug('[UNIFIED WITHDRAW CALLBACK] Dados recebidos: ' . json_encode($data));

            // Tenta identificar a adquirente pelos dados recebidos
            $adquirente = $this->identifyAdquirente($data);
            
            if (!$adquirente) {
                Log::warning('[UNIFIED WITHDRAW CALLBACK] Adquirente não identificada pelos dados');
                return response()->json(['status' => false, 'message' => 'Adquirente não identificada'], 400);
            }

            Log::info("[UNIFIED WITHDRAW CALLBACK] Redirecionando para adquirente: $adquirente");

            // Redireciona para o controller da adquirente específica
            // Apenas Pagar.me permanece ativo (mas não suporta saques PIX diretamente)
            switch ($adquirente) {
                case 'pagarme':
                    // Pagar.me não suporta saques PIX diretamente
                    Log::warning("[UNIFIED WITHDRAW CALLBACK] Pagar.me não suporta saques PIX");
                    return response()->json(['status' => false, 'message' => 'Saque PIX não disponível'], 400);
                    
                default:
                    Log::warning("[UNIFIED WITHDRAW CALLBACK] Adquirente não suportada ou removida: $adquirente");
                    return response()->json(['status' => false, 'message' => 'Adquirente não suportada para saques'], 400);
            }

        } catch (\Exception $e) {
            Log::error('[UNIFIED WITHDRAW CALLBACK] Erro: ' . $e->getMessage());
            Log::error('[UNIFIED WITHDRAW CALLBACK] Stack trace: ' . $e->getTraceAsString());
            return response()->json(['status' => false, 'message' => 'Erro interno do servidor'], 500);
        }
    }

    /**
     * Teste do callback unificado
     */
    public function testCallback(Request $request)
    {
        $data = $request->all();
        Log::info('[UNIFIED CALLBACK TEST] Dados recebidos: ' . json_encode($data));
        
        $adquirente = $this->identifyAdquirente($data);
        
        return response()->json([
            'status' => true,
            'message' => 'Callback unificado testado com sucesso',
            'identified_adquirente' => $adquirente,
            'received_data' => $data,
            'timestamp' => now()->toDateTimeString()
        ]);
    }
}
