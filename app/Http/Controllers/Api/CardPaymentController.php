<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Models\User;
use App\Models\CheckoutOrders;
use App\Models\Solicitacoes;
use App\Services\PrimePay7Service;
use App\Helpers\Helper;
use App\Models\App as AppModel;

class CardPaymentController extends Controller
{
    /**
     * Cria um pagamento com cartão via API
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function createPayment(Request $request)
    {
        try {
            Log::info('💳 API Card Payment - Requisição recebida:', $request->all());

            // Validação dos dados
            $validator = Validator::make($request->all(), [
                'amount' => 'required|numeric|min:1',
                'client_name' => 'required|string|max:255',
                'client_email' => 'required|email',
                'client_document' => 'required|string',
                'client_phone' => 'required|string',
                'installments' => 'required|integer|min:1|max:12',
                
                // Dados do cartão (tokenizado ou raw)
                'card.hash' => 'required_without_all:card.number,card.holder_name,card.expiration_month,card.expiration_year,card.cvv',
                'card.number' => 'required_without:card.hash|string',
                'card.holder_name' => 'required_without:card.hash|string',
                'card.expiration_month' => 'required_without:card.hash|integer|min:1|max:12',
                'card.expiration_year' => 'required_without:card.hash|integer|min:2025',
                'card.cvv' => 'required_without:card.hash|string|min:3|max:4',
                
                // Opcionais
                'description' => 'nullable|string|max:500',
                'return_url' => 'nullable|url',
                'postback_url' => 'nullable|url',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 400);
            }

            // Buscar usuário autenticado (injetado pelo middleware CheckTokenAndSecret)
            $user = $request->user_auth;

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuário não encontrado'
                ], 404);
            }

            // Obter adquirente padrão para cartão
            $adquirente = Helper::adquirenteDefault($user, 'card_billet');

            if ($adquirente !== 'primepay7') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Pagamento com cartão não configurado. Configure PrimePay7 como adquirente padrão para cartão.'
                ], 400);
            }

            // Verificar se PrimePay7 está ativo
            $primepay7 = \App\Models\PrimePay7::first();
            if (!$primepay7 || !$primepay7->status) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Adquirente de cartão não disponível no momento'
                ], 503);
            }

            // Preparar dados da transação
            $amount = floatval($request->amount);
            $installments = intval($request->installments);

            // Calcular taxa
            $setting = AppModel::first();
            $taxaCalculada = \App\Helpers\TaxaFlexivelHelper::calcularTaxaDeposito($amount, $setting, $user);
            $deposito_liquido = $taxaCalculada['deposito_liquido'];
            $taxa_cash_in = $taxaCalculada['taxa_cash_in'];

            // Gerar ID único para a transação
            $externalReference = 'API_CARD_' . uniqid() . '_' . time();

            // Preparar payload para PrimePay7
            $cardData = $request->card;
            $saleData = [
                'amount' => (int) ($amount * 100), // Converter para centavos
                'installments' => $installments,
                'items' => [
                    [
                        'title' => $request->description ?? 'Pagamento via API',
                        'unitPrice' => (int) ($amount * 100),
                        'quantity' => 1,
                        'tangible' => false
                    ]
                ],
                'customer' => [
                    'name' => $request->client_name,
                    'email' => $request->client_email,
                    'document' => [
                        'type' => 'cpf',
                        'number' => preg_replace('/\D/', '', $request->client_document)
                    ],
                    'phone' => preg_replace('/\D/', '', $request->client_phone)
                ],
                'card' => [
                    'hash' => $cardData['hash'] ?? null,
                    'number' => $cardData['number'] ?? null,
                    'holderName' => $cardData['holder_name'] ?? null,
                    'expirationMonth' => $cardData['expiration_month'] ?? null,
                    'expirationYear' => $cardData['expiration_year'] ?? null,
                    'cvv' => $cardData['cvv'] ?? null,
                ],
                'returnURL' => $request->return_url ?? url('/api/card/callback'),
                'postbackUrl' => $request->postback_url ?? url('/api/card/webhook')
            ];

            // Processar pagamento na PrimePay7
            Log::info('💳 Enviando pagamento para PrimePay7:', ['user_id' => $user->user_id, 'amount' => $amount]);
            
            $primePay7Service = new PrimePay7Service();
            $response = $primePay7Service->createCardSale($saleData);

            if (isset($response['id'])) {
                $transactionId = $response['id'];
                $status = $response['status'] ?? 'processing';

                // Criar registro na tabela solicitacoes
                $solicitacao = Solicitacoes::create([
                    'user_id' => $user->user_id,
                    'externalreference' => $externalReference,
                    'amount' => $amount,
                    'client_name' => $request->client_name,
                    'client_document' => preg_replace('/\D/', '', $request->client_document),
                    'client_email' => $request->client_email,
                    'date' => now(),
                    'status' => 'WAITING_FOR_APPROVAL', // Aguardando confirmação
                    'idTransaction' => $transactionId,
                    'deposito_liquido' => $deposito_liquido,
                    'qrcode_pix' => '',
                    'paymentcode' => '',
                    'paymentCodeBase64' => '',
                    'adquirente_ref' => 'PrimePay7',
                    'taxa_cash_in' => $taxa_cash_in,
                    'taxa_pix_cash_in_adquirente' => 0,
                    'taxa_pix_cash_in_valor_fixo' => 0,
                    'client_telefone' => $request->client_phone,
                    'executor_ordem' => 'API Card Payment',
                    'descricao_transacao' => $request->description ?? 'Pagamento com Cartão via API',
                ]);

                Log::info('✅ Pagamento criado com sucesso:', [
                    'transaction_id' => $transactionId,
                    'solicitacao_id' => $solicitacao->id,
                    'status' => $status
                ]);

                // Mapear status da PrimePay7 para resposta da API
                $statusMap = [
                    'waiting_payment' => 'pending',
                    'pending' => 'pending',
                    'processing' => 'processing',
                    'approved' => 'approved',
                    'paid' => 'paid',
                    'refused' => 'refused',
                    'cancelled' => 'cancelled',
                ];

                $apiStatus = $statusMap[$status] ?? 'processing';

                return response()->json([
                    'status' => 'success',
                    'data' => [
                        'transaction_id' => $transactionId,
                        'external_reference' => $externalReference,
                        'status' => $apiStatus,
                        'amount' => $amount,
                        'amount_net' => $deposito_liquido,
                        'fee' => $taxa_cash_in,
                        'installments' => $installments,
                        'created_at' => now()->toIso8601String(),
                        'return_url' => $response['returnURL'] ?? null,
                    ]
                ], 201);

            } else {
                // Erro ao processar pagamento
                $errorMessage = 'Erro ao processar pagamento';
                
                if (isset($response['errors']) && is_array($response['errors'])) {
                    $errorMessage = implode(', ', array_map(function($error) {
                        return $error['message'] ?? $error;
                    }, $response['errors']));
                } elseif (isset($response['message'])) {
                    $errorMessage = $response['message'];
                }

                Log::error('❌ Erro ao criar pagamento PrimePay7:', [
                    'user_id' => $user->user_id,
                    'response' => $response
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => $errorMessage
                ], 400);
            }

        } catch (\Exception $e) {
            Log::error('❌ Exceção ao criar pagamento com cartão via API:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Erro interno ao processar pagamento',
                'debug' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Consulta o status de um pagamento
     * 
     * @param Request $request
     * @param string $transactionId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPaymentStatus(Request $request, $transactionId)
    {
        try {
            Log::info('🔍 API Card Payment - Consultando status:', ['transaction_id' => $transactionId]);

            // Buscar usuário autenticado
            $user = $request->user_auth;

            // Buscar transação
            $solicitacao = Solicitacoes::where('idTransaction', $transactionId)
                ->where('user_id', $user->user_id)
                ->first();

            if (!$solicitacao) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Transação não encontrada'
                ], 404);
            }

            // Mapear status interno para API
            $statusMap = [
                'WAITING_FOR_APPROVAL' => 'pending',
                'PENDING' => 'processing',
                'PAID_OUT' => 'paid',
                'CANCELLED' => 'cancelled',
                'REFUNDED' => 'refunded',
            ];

            $apiStatus = $statusMap[$solicitacao->status] ?? 'processing';

            return response()->json([
                'status' => 'success',
                'data' => [
                    'transaction_id' => $solicitacao->idTransaction,
                    'external_reference' => $solicitacao->externalreference,
                    'status' => $apiStatus,
                    'amount' => floatval($solicitacao->amount),
                    'amount_net' => floatval($solicitacao->deposito_liquido),
                    'fee' => floatval($solicitacao->taxa_cash_in),
                    'client_name' => $solicitacao->client_name,
                    'client_email' => $solicitacao->client_email,
                    'description' => $solicitacao->descricao_transacao,
                    'created_at' => $solicitacao->created_at->toIso8601String(),
                    'updated_at' => $solicitacao->updated_at->toIso8601String(),
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('❌ Erro ao consultar status do pagamento:', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Erro ao consultar status do pagamento'
            ], 500);
        }
    }

    /**
     * Webhook para receber atualizações da PrimePay7 (via API)
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function webhook(Request $request)
    {
        try {
            $data = $request->all();
            Log::info('📬 API Card Payment - Webhook recebido:', $data);

            if (!isset($data['type']) || !isset($data['data'])) {
                return response()->json(['status' => 'error', 'message' => 'Invalid webhook structure'], 400);
            }

            $transactionData = $data['data'];
            $transactionId = $transactionData['id'] ?? null;
            $status = $transactionData['status'] ?? null;

            if (!$transactionId || !$status) {
                return response()->json(['status' => 'error', 'message' => 'Missing transaction ID or status'], 400);
            }

            // Buscar transação
            $solicitacao = Solicitacoes::where('idTransaction', $transactionId)->first();

            if (!$solicitacao) {
                Log::warning('⚠️ Transação não encontrada:', ['transaction_id' => $transactionId]);
                return response()->json(['status' => 'error', 'message' => 'Transaction not found'], 404);
            }

            // Mapear status
            $statusMap = [
                'approved' => 'PAID_OUT',
                'paid' => 'PAID_OUT',
                'refused' => 'CANCELLED',
                'cancelled' => 'CANCELLED',
                'refunded' => 'REFUNDED',
                'chargeback' => 'CANCELLED',
            ];

            $newStatus = $statusMap[$status] ?? null;

            if ($newStatus && $solicitacao->status !== $newStatus) {
                // Atualizar status
                $solicitacao->update(['status' => $newStatus]);

                // Se foi aprovado, creditar saldo
                if ($newStatus === 'PAID_OUT') {
                    $user = User::where('user_id', $solicitacao->user_id)->first();
                    if ($user) {
                        Helper::incrementAmount($user, $solicitacao->deposito_liquido, 'saldo');
                        Helper::calculaSaldoLiquido($user->user_id);

                        Log::info('✅ Saldo creditado via API:', [
                            'user_id' => $user->user_id,
                            'amount' => $solicitacao->deposito_liquido
                        ]);
                    }
                }

                Log::info('✅ Status atualizado via webhook API:', [
                    'transaction_id' => $transactionId,
                    'old_status' => $solicitacao->status,
                    'new_status' => $newStatus
                ]);
            }

            return response()->json(['status' => 'success'], 200);

        } catch (\Exception $e) {
            Log::error('❌ Erro ao processar webhook API:', ['error' => $e->getMessage()]);
            return response()->json(['status' => 'error', 'message' => 'Internal error'], 500);
        }
    }
}


