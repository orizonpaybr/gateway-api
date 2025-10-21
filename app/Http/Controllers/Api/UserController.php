<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Transactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    /**
     * Obter saldo do usuário
     */
    public function getBalance(Request $request)
    {
        try {
            // Obter usuário via JWT (padrão dos demais endpoints)
            $user = $request->user() ?? $request->user_auth;
            // Fallback de compatibilidade (token antigo/base64) e token+secret
            if (!$user) {
                $user = $this->getUserFromToken($request) ?? $this->getUserFromRequest($request);
            }
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }

            // Cache Redis para dados de saldo (TTL: 2 minutos)
            $cacheKey = "user_balance_{$user->username}";
            $balanceData = \Illuminate\Support\Facades\Cache::remember($cacheKey, 120, function() use ($user) {
                // Calcular totais de transações (entradas) - apenas COMPLETED e PAID_OUT
                $totalInflows = \App\Models\Solicitacoes::where('user_id', $user->username)
                    ->whereIn('status', ['PAID_OUT', 'COMPLETED'])
                    ->sum('amount');

                // Calcular totais de saques (saídas) - apenas COMPLETED e PAID_OUT
                $totalOutflows = \App\Models\SolicitacoesCashOut::where('user_id', $user->username)
                    ->whereIn('status', ['PAID_OUT', 'COMPLETED'])
                    ->sum('amount');

                return [
                    'totalInflows' => $totalInflows,
                    'totalOutflows' => $totalOutflows
                ];
            });

            $totalInflows = $balanceData['totalInflows'];
            $totalOutflows = $balanceData['totalOutflows'];

            // Log para debug
            Log::info('Saldo calculado', [
                'user_id' => $user->username,
                'saldo_atual' => $user->saldo ?? 0,
                'total_inflows' => $totalInflows,
                'total_outflows' => $totalOutflows,
                'filtro_status' => ['PAID_OUT', 'COMPLETED']
            ]);

            // Determinar tipo de pessoa por CPF/CNPJ e status legível (Aprovado/Pendente)
            $doc = preg_replace('/\D/', '', (string) ($user->cpf_cnpj ?? ''));
            $tipoPessoa = ($doc && strlen($doc) > 11) ? 'PJ' : 'PF';
            $tipoPessoaLegivel = $tipoPessoa === 'PJ' ? 'Pessoa Jurídica' : 'Pessoa Física';
            $statusAtual = $user->status == 1 ? 'Aprovado' : 'Pendente';

            return response()->json([
                'success' => true,
                'data' => [
                    'current' => $user->saldo ?? 0,
                    'totalInflows' => $totalInflows,
                    'totalOutflows' => $totalOutflows,
                ]
            ])->header('Access-Control-Allow-Origin', '*');

        } catch (\Exception $e) {
            Log::error('Erro ao obter saldo', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500)->header('Access-Control-Allow-Origin', '*');
        }
    }

    /**
     * Obter transações do usuário
     */
    public function getTransactions(Request $request)
    {
        try {
            // Pegar usuário do middleware JWT (igual aos outros endpoints que funcionam)
            $user = $request->user() ?? $request->user_auth;
            
            if (!$user) {
                Log::error('getTransactions - Usuário não encontrado no request', [
                    'has_user' => !empty($request->user()),
                    'has_user_auth' => !empty($request->user_auth)
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }

            // Parâmetros de paginação
            $page = $request->get('page', 1);
            $limit = min($request->get('limit', 10), 50); // Máximo 50 por página para performance

            // Parâmetros de filtro
            $tipo = $request->get('tipo'); // 'deposito', 'saque', ou null (todos)
            $status = $request->get('status'); // Status específico ou null
            $busca = $request->get('busca'); // Termo de busca
            $dataInicio = $request->get('data_inicio');
            $dataFim = $request->get('data_fim');

            // Cache Redis para transações (TTL: 2 minutos)
            $cacheKey = sprintf('user_transactions_%s_%s_%s_%s_%s_%s_%s_%s', 
                $user->username, 
                $page, 
                $limit, 
                $tipo ?? 'all', 
                $status ?? 'all', 
                $busca ?? 'none', 
                $dataInicio ?? 'none', 
                $dataFim ?? 'none'
            );
            
            $transactionsData = \Illuminate\Support\Facades\Cache::remember($cacheKey, 120, function() use ($user, $page, $limit, $tipo, $status, $busca, $dataInicio, $dataFim) {
                $depositosQuery = \App\Models\Solicitacoes::where('user_id', $user->username)
                ->select([
                    'id',
                    'idTransaction',
                    'externalreference',
                    'amount',
                    'deposito_liquido as valor_liquido',
                    'taxa_cash_in as taxa',
                    'status',
                    'date',
                    'created_at',
                    DB::raw("CAST(client_name AS CHAR CHARACTER SET utf8mb4) as nome_cliente"),
                    DB::raw("CAST(client_document AS CHAR CHARACTER SET utf8mb4) as documento"),
                    DB::raw("CAST(COALESCE(adquirente_ref, 'Sistema') AS CHAR CHARACTER SET utf8mb4) as adquirente"),
                    DB::raw("CAST(COALESCE(descricao_transacao, 'Pagamento Recebido') AS CHAR CHARACTER SET utf8mb4) as descricao"),
                    DB::raw("'deposito' as tipo")
                ]);

            // Buscar saques
            $saquesQuery = \App\Models\SolicitacoesCashOut::where('user_id', $user->username)
                ->select([
                    'id',
                    'idTransaction',
                    'externalreference',
                    'amount',
                    'cash_out_liquido as valor_liquido',
                    'taxa_cash_out as taxa',
                    'status',
                    'date',
                    'created_at',
                    DB::raw("CAST(beneficiaryname AS CHAR CHARACTER SET utf8mb4) as nome_cliente"),
                    DB::raw("CAST(beneficiarydocument AS CHAR CHARACTER SET utf8mb4) as documento"),
                    DB::raw("CAST(COALESCE(executor_ordem, 'Sistema') AS CHAR CHARACTER SET utf8mb4) as adquirente"),
                    DB::raw("CAST(COALESCE(descricao_transacao, 'Pagamento Enviado') AS CHAR CHARACTER SET utf8mb4) as descricao"),
                    DB::raw("'saque' as tipo")
                ]);

            // Aplicar filtro de período se fornecido
            if ($dataInicio && $dataFim) {
                $depositosQuery->whereBetween('date', [$dataInicio, $dataFim]);
                $saquesQuery->whereBetween('date', [$dataInicio, $dataFim]);
            }

            // Aplicar filtro de status se fornecido
            if ($status) {
                $depositosQuery->where('status', $status);
                $saquesQuery->where('status', $status);
            }

            // Aplicar filtro de busca se fornecido (busca por ID ou nome)
            if ($busca) {
                $depositosQuery->where(function($query) use ($busca) {
                    $query->where('idTransaction', 'like', "%{$busca}%")
                          ->orWhere('externalreference', 'like', "%{$busca}%")
                          ->orWhere('client_name', 'like', "%{$busca}%");
                });
                
                $saquesQuery->where(function($query) use ($busca) {
                    $query->where('idTransaction', 'like', "%{$busca}%")
                          ->orWhere('externalreference', 'like', "%{$busca}%")
                          ->orWhere('beneficiaryname', 'like', "%{$busca}%");
                });
            }

            // Unir as queries baseado no filtro de tipo
            if ($tipo === 'deposito') {
                $query = $depositosQuery;
            } elseif ($tipo === 'saque') {
                $query = $saquesQuery;
            } else {
                // Unir depósitos e saques usando UNION ALL
                $query = $depositosQuery->union($saquesQuery);
            }

            // Contar total de registros antes da paginação
            $totalQuery = DB::query()->fromSub($query, 'transactions');
            $total = $totalQuery->count();

            // Ordenar e paginar
            $offset = ($page - 1) * $limit;
            $transactions = DB::query()
                ->fromSub($query, 'transactions')
                ->orderBy('date', 'desc')
                ->orderBy('created_at', 'desc')
                ->skip($offset)
                ->take($limit)
                ->get();

            // Formatar dados
            $transactionsFormatted = $transactions->map(function($transaction) {
                return [
                    'id' => (int) $transaction->id,
                    'transaction_id' => $transaction->idTransaction ?? $transaction->externalreference ?? 'N/A',
                    'tipo' => $transaction->tipo ?? 'deposito',
                    'amount' => (float) ($transaction->amount ?? 0),
                    'valor_liquido' => (float) ($transaction->valor_liquido ?? 0),
                    'taxa' => (float) ($transaction->taxa ?? 0),
                    'status' => $transaction->status ?? 'PENDING',
                    'status_legivel' => $this->mapStatus($transaction->status ?? 'PENDING'),
                    'data' => $transaction->date ?? now()->format('Y-m-d H:i:s'),
                    'created_at' => $transaction->created_at ?? now()->format('Y-m-d H:i:s'),
                    'nome_cliente' => $transaction->nome_cliente ?? 'Cliente',
                    'documento' => $transaction->documento ?? '00000000000',
                    'adquirente' => $transaction->adquirente ?? 'Sistema',
                    'descricao' => $transaction->descricao ?? ($transaction->tipo === 'deposito' ? 'Pagamento Recebido' : 'Pagamento Enviado')
                ];
            });

                return [
                    'transactions' => $transactionsFormatted,
                    'total' => $total,
                    'total_pages' => ceil($total / $limit)
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'data' => $transactionsData['transactions'],
                    'current_page' => (int) $page,
                    'last_page' => (int) $transactionsData['total_pages'],
                    'per_page' => (int) $limit,
                    'total' => (int) $transactionsData['total'],
                    'from' => (($page - 1) * $limit) + 1,
                    'to' => min($page * $limit, $transactionsData['total'])
                ]
            ])->header('Access-Control-Allow-Origin', '*');

        } catch (\Exception $e) {
            Log::error('Erro ao obter transações', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500)->header('Access-Control-Allow-Origin', '*');
        }
    }

    /**
     * Obter transação específica por ID
     */
    public function getTransactionById(Request $request, $id)
    {
        try {
            // Pegar usuário do middleware JWT (igual aos outros endpoints que funcionam)
            $user = $request->user() ?? $request->user_auth;
            
            if (!$user) {
                Log::error('getTransactionById - Usuário não encontrado no request', [
                    'has_user' => !empty($request->user()),
                    'has_user_auth' => !empty($request->user_auth)
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }

            // Cache Redis para transação específica (TTL: 5 minutos)
            $cacheKey = "transaction_by_id_{$user->username}_{$id}";
            
            $transactionData = \Illuminate\Support\Facades\Cache::remember($cacheKey, 300, function() use ($user, $id) {
                $deposito = \App\Models\Solicitacoes::where('user_id', $user->username)
                ->where(function($query) use ($id) {
                    $query->where('id', $id)
                          ->orWhere('idTransaction', $id)
                          ->orWhere('externalreference', $id);
                })
                ->first();

                if ($deposito) {
                    Log::info('Transação encontrada em depósitos', [
                        'id' => $deposito->id,
                        'status' => $deposito->status,
                        'amount' => $deposito->amount
                    ]);

                    return [
                        'id' => $deposito->id,
                        'transaction_id' => $deposito->idTransaction ?? $deposito->externalreference,
                        'tipo' => 'deposito',
                        'metodo' => 'PIX',
                        'movimento' => 'Débito',
                        'amount' => (float) $deposito->amount,
                        'valor_liquido' => (float) $deposito->deposito_liquido,
                        'taxa' => (float) $deposito->taxa_cash_in,
                        'status' => $deposito->status,
                        'status_legivel' => $this->mapStatus($deposito->status),
                        'data' => $deposito->date,
                        'created_at' => $deposito->created_at,
                        'updated_at' => $deposito->updated_at,
                        // Origem (quem pagou)
                        'origem' => [
                            'nome' => $deposito->client_name ?? 'Cliente',
                            'documento' => $deposito->client_document ?? '00000000000'
                        ],
                        // Destino (nossa conta)
                        'destino' => [
                            'nome' => $user->name ?? $user->username,
                            'documento' => $user->cpf_cnpj ?? '00000000000'
                        ],
                        'adquirente' => $deposito->adquirente_ref ?? 'Sistema',
                        'codigo_autenticacao' => $deposito->idTransaction ?? $deposito->externalreference,
                        'qrcode' => $deposito->qrcode_pix ?? null,
                        'descricao' => $deposito->descricao_transacao ?? 'Pagamento Recebido'
                    ];
                }

                // Se não encontrou em depósitos, procurar em saques (saídas)
                $saque = \App\Models\SolicitacoesCashOut::where('user_id', $user->username)
                    ->where(function($query) use ($id) {
                        $query->where('id', $id)
                              ->orWhere('idTransaction', $id)
                              ->orWhere('externalreference', $id);
                    })
                    ->first();

                if ($saque) {
                    Log::info('Transação encontrada em saques', [
                        'id' => $saque->id,
                        'status' => $saque->status,
                        'amount' => $saque->amount
                    ]);

                    return [
                        'id' => $saque->id,
                        'transaction_id' => $saque->idTransaction ?? $saque->externalreference,
                        'tipo' => 'saque',
                        'metodo' => 'PIX',
                        'movimento' => 'Débito',
                        'amount' => (float) $saque->amount,
                        'valor_liquido' => (float) $saque->cash_out_liquido,
                        'taxa' => (float) $saque->taxa_cash_out,
                        'status' => $saque->status,
                        'status_legivel' => $this->mapStatus($saque->status),
                        'data' => $saque->date,
                        'created_at' => $saque->created_at,
                        'updated_at' => $saque->updated_at,
                        // Origem (nossa conta)
                        'origem' => [
                            'nome' => $user->name ?? $user->username,
                            'documento' => $user->cpf_cnpj ?? '00000000000'
                        ],
                        // Destino (quem recebeu)
                        'destino' => [
                            'nome' => $saque->beneficiaryname ?? 'Beneficiário',
                            'documento' => $saque->beneficiarydocument ?? '00000000000'
                        ],
                        'pix_key' => $saque->pix ?? '',
                        'pix_key_type' => $saque->pixkey ?? 'Não informado',
                        'adquirente' => $saque->executor_ordem ?? 'Sistema',
                        'codigo_autenticacao' => $saque->idTransaction ?? $saque->externalreference,
                        'end_to_end' => $saque->end_to_end ?? null,
                        'descricao' => $saque->descricao_transacao ?? 'Pagamento Enviado'
                    ];
                }

                // Não encontrou a transação
                return null;
            });

            if ($transactionData) {
                return response()->json([
                    'success' => true,
                    'data' => $transactionData
                ])->header('Access-Control-Allow-Origin', '*');
            }

            // Não encontrou a transação
            Log::info('Transação não encontrada', [
                'transaction_id' => $id,
                'user_id' => $user->username
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Transação não encontrada'
            ], 404)->header('Access-Control-Allow-Origin', '*');

        } catch (\Exception $e) {
            Log::error('Erro ao obter transação por ID', [
                'error' => $e->getMessage(),
                'transaction_id' => $id,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500)->header('Access-Control-Allow-Origin', '*');
        }
    }


    /**
     * Gerar QR Code para recebimento PIX
     */
    public function generatePixQR(Request $request)
    {
        try {
            $user = $this->getUserFromRequest($request);
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }

            $validator = Validator::make($request->all(), [
                'amount' => 'required|numeric|min:0.01',
                'description' => 'nullable|string|max:255'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 400)->header('Access-Control-Allow-Origin', '*');
            }

            $amount = $request->input('amount');
            $description = $request->input('description', 'Depósito via PIX');

            // Usar o sistema real de geração de QR Code através dos Traits
            $adquirenteDefault = \App\Helpers\Helper::adquirenteDefault($user->username);
            
            Log::info('Gerando QR Code PIX via API', [
                'user_id' => $user->username,
                'amount' => $amount,
                'adquirente' => $adquirenteDefault
            ]);

            // Preparar dados para o trait
            $requestData = new \Illuminate\Http\Request();
            $requestData->merge([
                'amount' => $amount,
                'description' => $description,
                'debtor_name' => $user->name ?? 'Cliente',
                'debtor_document_number' => $user->cpf ?? '00000000000',
                'email' => $user->email ?? 'cliente@hkpay.shop',
                'phone' => $user->telefone ?? '11999999999',
                'postback' => env('APP_URL') . '/api/callback'
            ]);
            
            // Simular usuário autenticado no request
            $requestData->setUserResolver(function () use ($user) {
                return $user;
            });

            // Escolher o trait baseado no adquirente padrão
            $response = null;
            switch ($adquirenteDefault) {
                case 'woovi':
                    $response = \App\Traits\WooviTrait::requestPaymentWoovi($requestData);
                    break;
                case 'bspay':
                    $response = \App\Traits\BSPayTrait::requestDepositBSPay($requestData);
                    break;
                case 'pixup':
                    $response = \App\Traits\PixupTrait::requestDepositPixup($requestData);
                    break;
                case 'xdpag':
                    $response = \App\Traits\XDPagTrait::requestDepositXDPag($requestData);
                    break;
                case 'primepay7':
                    $response = \App\Traits\PrimePay7Trait::requestDepositPrimePay7($requestData);
                    break;
                case 'asaas':
                    $response = \App\Traits\AsaasTrait::requestDepositAsaas($requestData);
                    break;
                default:
                    // Fallback para Woovi se não encontrar o adquirente
                    $response = \App\Traits\WooviTrait::requestPaymentWoovi($requestData);
                    break;
            }

            if (!$response || $response['status'] !== 200) {
                Log::error('Erro ao gerar QR Code via adquirente', [
                    'adquirente' => $adquirenteDefault,
                    'response' => $response,
                    'user_id' => $user->username
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Erro ao gerar QR Code PIX'
                ], 500)->header('Access-Control-Allow-Origin', '*');
            }

            // Formatar resposta para o formato esperado pelo app
            $qrData = $response['data'];
            
            return response()->json([
                'success' => true,
                'data' => [
                    'amount' => $amount,
                    'description' => $description,
                    'user_id' => $user->username,
                    'qr_code' => $qrData['qrcode'] ?? $qrData['charge']['brCode'] ?? null,
                    'qr_code_image_url' => $qrData['qr_code_image_url'] ?? $qrData['charge']['qrCode'] ?? null,
                    'transaction_id' => $qrData['idTransaction'] ?? $qrData['charge']['id'] ?? null,
                    'expires_at' => now()->addHours(24)->toISOString(),
                    'adquirente' => $adquirenteDefault
                ]
            ])->header('Access-Control-Allow-Origin', '*');

        } catch (\Exception $e) {
            Log::error('Erro ao gerar QR Code PIX', [
                'error' => $e->getMessage(),
                'user_id' => $user->username ?? 'unknown',
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500)->header('Access-Control-Allow-Origin', '*');
        }
    }

    /**
     * Realizar saque PIX
     */
    public function makePixWithdraw(Request $request)
    {
        try {
            $user = $this->getUserFromRequest($request);
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }

            $validator = Validator::make($request->all(), [
                'amount' => 'required|numeric|min:0.01',
                'pix_key' => 'required|string',
                'description' => 'nullable|string|max:255',
                'pin' => 'required|string|size:6'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 400)->header('Access-Control-Allow-Origin', '*');
            }

            $amount = $request->input('amount');
            $pixKey = $request->input('pix_key');
            $description = $request->input('description', 'Saque via PIX');
            $pin = $request->input('pin');

            // Verificar se o usuário tem saldo suficiente
            if ($user->saldo < $amount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Saldo insuficiente'
                ], 400)->header('Access-Control-Allow-Origin', '*');
            }

            // Verificar PIN (simplificado - em produção, usar hash)
            if ($pin !== '123456') { // PIN padrão para demonstração
                return response()->json([
                    'success' => false,
                    'message' => 'PIN incorreto'
                ], 400)->header('Access-Control-Allow-Origin', '*');
            }

            // Usar o sistema real de saque através dos Traits
            $adquirenteDefault = \App\Helpers\Helper::adquirenteDefault($user->username);
            
            Log::info('Realizando saque PIX via API', [
                'user_id' => $user->username,
                'amount' => $amount,
                'pix_key' => $pixKey,
                'adquirente' => $adquirenteDefault
            ]);

            // Preparar dados para o trait
            $requestData = new \Illuminate\Http\Request();
            $requestData->merge([
                'amount' => $amount,
                'pixKey' => $pixKey,
                'pixKeyType' => $this->detectPixKeyType($pixKey),
                'baasPostbackUrl' => env('APP_URL') . '/api/callback',
                'saque_automatico' => true
            ]);
            
            // Simular usuário autenticado no request
            $requestData->setUserResolver(function () use ($user) {
                return $user;
            });

            // Escolher o trait baseado no adquirente padrão
            $response = null;
            switch ($adquirenteDefault) {
                case 'woovi':
                    $response = \App\Traits\WooviTrait::requestSaqueWoovi($requestData);
                    break;
                case 'bspay':
                    $response = \App\Traits\BSPayTrait::requestPaymentBSPay($requestData);
                    break;
                case 'pixup':
                    $response = \App\Traits\PixupTrait::requestPaymentPixup($requestData);
                    break;
                case 'xdpag':
                    $response = \App\Traits\XDPagTrait::requestPaymentXDPag($requestData);
                    break;
                case 'primepay7':
                    $response = \App\Traits\PrimePay7Trait::requestPaymentPrimePay7($requestData);
                    break;
                case 'asaas':
                    $response = \App\Traits\AsaasTrait::requestPaymentAsaas($requestData);
                    break;
                default:
                    // Fallback para Woovi se não encontrar o adquirente
                    $response = \App\Traits\WooviTrait::requestSaqueWoovi($requestData);
                    break;
            }

            if (!$response || $response['status'] !== 200) {
                Log::error('Erro ao realizar saque via adquirente', [
                    'adquirente' => $adquirenteDefault,
                    'response' => $response,
                    'user_id' => $user->username
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Erro ao processar saque PIX'
                ], 500)->header('Access-Control-Allow-Origin', '*');
            }

            // Formatar resposta para o formato esperado pelo app
            $withdrawData = $response['data'];
            
            return response()->json([
                'success' => true,
                'data' => [
                    'transaction_id' => $withdrawData['id'] ?? null,
                    'amount' => $amount,
                    'pix_key' => $pixKey,
                    'description' => $description,
                    'status' => $withdrawData['withdrawStatusId'] ?? 'PROCESSING',
                    'estimated_time' => '5-10 minutos',
                    'created_at' => $withdrawData['createdAt'] ?? now()->toISOString(),
                    'adquirente' => $adquirenteDefault
                ]
            ])->header('Access-Control-Allow-Origin', '*');

        } catch (\Exception $e) {
            Log::error('Erro ao realizar saque PIX', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500)->header('Access-Control-Allow-Origin', '*');
        }
    }

    /**
     * Obter extrato combinado (entradas e saídas)
     */
    public function getStatement(Request $request)
    {
        try {
            $user = $this->getUserFromRequest($request);
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }

            // Fazer requisições para obter entradas e saídas do período atual
            $inflowsResponse = Http::get(url('/relatorio/entradas?periodo=hoje&user_id=' . $user->username));
            $outflowsResponse = Http::get(url('/relatorio/saidas?periodo=hoje&user_id=' . $user->username));

            $inflows = $inflowsResponse->successful() ? $inflowsResponse->json() : [];
            $outflows = $outflowsResponse->successful() ? $outflowsResponse->json() : [];

            return response()->json([
                'success' => true,
                'data' => [
                    'inflows' => [
                        'label' => 'Entradas de Hoje',
                        'data' => $inflows
                    ],
                    'outflows' => [
                        'label' => 'Saídas de Hoje',
                        'data' => $outflows
                    ],
                    'period' => 'hoje'
                ]
            ])->header('Access-Control-Allow-Origin', '*');

        } catch (\Exception $e) {
            Log::error('Erro ao obter extrato', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500)->header('Access-Control-Allow-Origin', '*');
        }
    }

    /**
     * Obter perfil completo do usuário
     */
    public function getProfile(Request $request)
    {
        try {
            // Usar autenticação do middleware verify.jwt
            $user = $request->user() ?? $request->user_auth;
            
            Log::info('getProfile - Usuário autenticado', [
                'user_id' => $user ? $user->username : 'null',
                'user_type' => get_class($user ?? 'null')
            ]);
            
            if (!$user) {
                Log::warning('getProfile - Usuário não autenticado');
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }

            // Cache Redis para dados do perfil (TTL: 5 minutos)
            $cacheKey = "user_profile_{$user->username}";
            $profileData = \Illuminate\Support\Facades\Cache::remember($cacheKey, 300, function() use ($user) {
                // Calcular informações derivadas (tipo PF/PJ e status legível)
                $doc = preg_replace('/\D/', '', (string) ($user->cpf_cnpj ?? ''));
                $tipoPessoa = ($doc && strlen($doc) > 11) ? 'PJ' : 'PF';
                $tipoPessoaLegivel = $tipoPessoa === 'PJ' ? 'Pessoa Jurídica' : 'Pessoa Física';
                $statusAtual = $user->status == 1 ? 'Aprovado' : 'Pendente';

                return [
                    'id' => $user->username,
                    'username' => $user->username,
                    'email' => $user->email ?? '',
                    'name' => $user->name ?? $user->username,
                    'phone' => $user->telefone ?? '',
                    'cnpj' => $user->cpf_cnpj ?? '',
                    'status' => $user->status == 1 ? 'active' : 'inactive',
                    'balance' => $user->saldo ?? 0,
                    'agency' => $user->agency ?? '',
                    'status_text' => $statusAtual,
                    'company' => [
                        'razao_social' => $user->razao_social ?? null,
                        'nome_fantasia' => $user->nome_fantasia ?? null,
                        'tipo_pessoa' => $tipoPessoa,
                        'tipo' => $tipoPessoaLegivel,
                        'area_atuacao' => $user->area_atuacao ?? null,
                        'status_cadastro' => $user->status_cadastro ?? null,
                        'status_atual' => $statusAtual,
                    ],
                    'contacts' => [
                        'telefone_principal' => $user->telefone ?? null,
                        'email_principal' => $user->email ?? null,
                    ],
                    'taxes' => [
                        'deposit' => [
                            'fixed' => (float) ($user->taxa_fixa_deposito ?? $user->taxa_cash_in_fixa ?? 0),
                            'percent' => (float) ($user->taxa_percentual_deposito ?? $user->taxa_cash_in ?? 0),
                            'after_limit_fixed' => (float) ($user->taxa_fixa_baixos ?? 0),
                            'after_limit_percent' => (float) ($user->taxa_percentual_altos ?? 0),
                        ],
                        'withdraw' => [
                            'dashboard' => [
                                'fixed' => (float) ($user->taxa_cash_out_fixa ?? 0),
                                'percent' => (float) ($user->taxa_cash_out ?? 0),
                                'after_limit_fixed' => (float) ($user->taxa_fixa_padrao_cash_out ?? 0),
                                'after_limit_percent' => (float) ($user->taxa_percentual_altos ?? 0),
                            ],
                            'api' => [
                                'fixed' => (float) ($user->taxa_saque_api ?? 0),
                                'percent' => (float) ($user->taxa_saque_cripto ?? 0),
                                'after_limit_fixed' => (float) ($user->taxa_fixa_pix ?? 0),
                                'after_limit_percent' => (float) ($user->taxa_percentual_pix ?? 0),
                            ],
                        ],
                        'affiliate' => [
                            'fixed' => (float) ($user->taxa_fixa_afiliado ?? 0),
                            'percent' => (float) ($user->taxa_percentual_afiliado ?? 0),
                        ],
                    ],
                    'limits' => [
                        'deposit_min' => (float) ($user->taxa_flexivel_valor_minimo ?? 15.00),
                        'withdraw_min' => (float) ($user->limite_mensal_pf ?? 50.00),
                        'retention_value' => (float) ($user->retencao_valor ?? 0),
                        'retention_percent' => (float) ($user->retencao_taxa ?? 0),
                    ],
                    'features' => [
                        'saque_automatico' => (bool) ($user->saque_automatico ?? false),
                        'saque_via_dashboard' => true,
                        'saque_via_api' => true,
                    ],
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $profileData
            ])->header('Access-Control-Allow-Origin', '*');

        } catch (\Exception $e) {
            Log::error('Erro ao obter perfil', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500)->header('Access-Control-Allow-Origin', '*');
        }
    }

    /**
     * Obter dados reais de faturamento e transações
     */
    public function getRealData(Request $request)
    {
        try {
            $user = $this->getUserFromRequest($request);
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }

            $periodo = $request->input('periodo', 'hoje'); // hoje, 7dias, mes, personalizado
            $dataInicio = $request->input('data_inicio');
            $dataFim = $request->input('data_fim');

            // Calcular datas baseado no período
            $dates = $this->calculateDateRange($periodo, $dataInicio, $dataFim);
            
            // Log para debug
            Log::info('Filtro de data aplicado', [
                'periodo' => $periodo,
                'inicio' => $dates['inicio']->format('Y-m-d H:i:s'),
                'fim' => $dates['fim']->format('Y-m-d H:i:s'),
                'user_id' => $user->username
            ]);

            // Buscar dados de entradas (depósitos) - apenas COMPLETED e PAID_OUT
            $entradasQuery = \App\Models\Solicitacoes::where('user_id', $user->username)
                ->whereBetween('date', [$dates['inicio'], $dates['fim']])
                ->whereIn('status', ['PAID_OUT', 'COMPLETED']);

            $totalEntradas = $entradasQuery->sum('amount');
            $totalEntradasLiquidas = $entradasQuery->sum('deposito_liquido');
            $totalTaxasEntradas = $entradasQuery->sum('taxa_cash_in');

            // Buscar dados de saídas (saques) - apenas COMPLETED e PAID_OUT
            $saidasQuery = \App\Models\SolicitacoesCashOut::where('user_id', $user->username)
                ->whereBetween('date', [$dates['inicio'], $dates['fim']])
                ->whereIn('status', ['PAID_OUT', 'COMPLETED']);

            $totalSaidas = $saidasQuery->sum('amount');
            $totalSaidasLiquidas = $saidasQuery->sum('cash_out_liquido');
            $totalTaxasSaidas = $saidasQuery->sum('taxa_cash_out');

            // Buscar transações para o extrato
            $transacoesEntradas = $entradasQuery->orderBy('date', 'desc')->get();
            $transacoesSaidas = $saidasQuery->orderBy('date', 'desc')->get();
            
            // Log para debug - verificar quantas transações foram encontradas
            Log::info('Transações aprovadas encontradas', [
                'periodo' => $periodo,
                'user_id' => $user->username,
                'saldo_atual' => $user->saldo ?? 0,
                'entradas_count' => $transacoesEntradas->count(),
                'saidas_count' => $transacoesSaidas->count(),
                'total_entradas' => $totalEntradas,
                'total_saidas' => $totalSaidas,
                'total_entradas_liquidas' => $totalEntradasLiquidas,
                'total_saidas_liquidas' => $totalSaidasLiquidas,
                'primeira_entrada_date' => $transacoesEntradas->first() ? $transacoesEntradas->first()->date : null,
                'ultima_entrada_date' => $transacoesEntradas->last() ? $transacoesEntradas->last()->date : null,
                'filtro_status' => ['PAID_OUT', 'COMPLETED']
            ]);

            // Combinar e ordenar transações
            $extrato = collect();
            
            // Adicionar entradas com tipo 'deposit'
            foreach ($transacoesEntradas as $entrada) {
                $extrato->push([
                    'id' => $entrada->id,
                    'tipo' => 'deposit',
                    'transaction_id' => $entrada->idTransaction ?? $entrada->externalreference,
                    'valor' => $entrada->amount,
                    'valor_liquido' => $entrada->deposito_liquido,
                    'taxa' => $entrada->taxa_cash_in,
                    'status' => $this->mapStatus($entrada->status),
                    'data' => $entrada->date,
                    'nome' => $entrada->client_name ?? 'Cliente',
                    'documento' => $entrada->client_document ?? '00000000000',
                    'adquirente' => $entrada->adquirente ?? 'Sistema'
                ]);
            }

            // Adicionar saídas com tipo 'withdraw'
            foreach ($transacoesSaidas as $saida) {
                $extrato->push([
                    'id' => $saida->id,
                    'tipo' => 'withdraw',
                    'transaction_id' => $saida->idTransaction ?? $saida->externalreference,
                    'valor' => $saida->amount,
                    'valor_liquido' => $saida->cash_out_liquido,
                    'taxa' => $saida->taxa_cash_out,
                    'status' => $this->mapStatus($saida->status),
                    'data' => $saida->date,
                    'nome' => $saida->beneficiaryname ?? 'Cliente',
                    'documento' => $saida->beneficiarydocument ?? '00000000000',
                    'pix_key' => $saida->pix ?? '',
                    'pix_key_type' => $saida->pixkey ?? '',
                    'adquirente' => $saida->adquirente ?? 'Sistema'
                ]);
            }

            // Ordenar por data (mais recente primeiro)
            $extrato = $extrato->sortByDesc('data')->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'periodo' => $periodo,
                    'data_inicio' => $dates['inicio'],
                    'data_fim' => $dates['fim'],
                    'resumo' => [
                        'total_entradas' => $totalEntradas,
                        'total_entradas_liquidas' => $totalEntradasLiquidas,
                        'total_taxas_entradas' => $totalTaxasEntradas,
                        'total_saidas' => $totalSaidas,
                        'total_saidas_liquidas' => $totalSaidasLiquidas,
                        'total_taxas_saidas' => $totalTaxasSaidas,
                        'saldo_atual' => $user->saldo ?? 0,
                        'saldo_periodo' => $user->saldo ?? 0
                    ],
                    'extrato' => $extrato,
                    'contadores' => [
                        'total_entradas_count' => $transacoesEntradas->count(),
                        'total_saidas_count' => $transacoesSaidas->count()
                    ]
                ]
            ])->header('Access-Control-Allow-Origin', '*');

        } catch (\Exception $e) {
            Log::error('Erro ao obter dados reais', [
                'error' => $e->getMessage(),
                'user_id' => $user->username ?? 'unknown',
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500)->header('Access-Control-Allow-Origin', '*');
        }
    }

    /**
     * Calcular intervalo de datas baseado no período
     */
    private function calculateDateRange($periodo, $dataInicio = null, $dataFim = null)
    {
        // Usar timezone do Brasil para garantir consistência
        $now = \Carbon\Carbon::now('America/Sao_Paulo');
        
        switch ($periodo) {
            case 'hoje':
                return [
                    'inicio' => $now->copy()->startOfDay(),
                    'fim' => $now->copy()->endOfDay()
                ];
                
            case '7dias':
                return [
                    'inicio' => $now->copy()->subDays(7)->startOfDay(),
                    'fim' => $now->copy()->endOfDay()
                ];
                
            case 'mes':
                return [
                    'inicio' => $now->copy()->startOfMonth(),
                    'fim' => $now->copy()->endOfMonth()
                ];
                
            case 'personalizado':
                if ($dataInicio && $dataFim) {
                    return [
                        'inicio' => \Carbon\Carbon::parse($dataInicio, 'America/Sao_Paulo')->startOfDay(),
                        'fim' => \Carbon\Carbon::parse($dataFim, 'America/Sao_Paulo')->endOfDay()
                    ];
                }
                // Fallback para hoje se não tiver datas
                return [
                    'inicio' => $now->copy()->startOfDay(),
                    'fim' => $now->copy()->endOfDay()
                ];
                
            case 'tudo':
                return [
                    'inicio' => \Carbon\Carbon::parse('2020-01-01', 'America/Sao_Paulo'),
                    'fim' => $now->copy()->endOfDay()
                ];
                
            default:
                return [
                    'inicio' => $now->copy()->startOfDay(),
                    'fim' => $now->copy()->endOfDay()
                ];
        }
    }

    /**
     * Mapear status para formato legível
     */
    private function mapStatus($status)
    {
        $statusMap = [
            'WAITING_FOR_APPROVAL' => 'Pendente',
            'PENDING' => 'Pendente',
            'PENDING_APPROVAL' => 'Pendente',
            'PAID_OUT' => 'Aprovado',
            'COMPLETED' => 'Aprovado',
            'APPROVED' => 'Aprovado',
            'CANCELLED' => 'Cancelado',
            'FAILED' => 'Falhou',
            'REJECTED' => 'Rejeitado'
        ];

        return $statusMap[$status] ?? $status;
    }
    private function detectPixKeyType($pixKey)
    {
        // Remover caracteres especiais para análise
        $cleanKey = preg_replace('/[^0-9a-zA-Z@.]/', '', $pixKey);
        
        // CPF (11 dígitos)
        if (preg_match('/^\d{11}$/', $cleanKey)) {
            return 'cpf';
        }
        
        // CNPJ (14 dígitos)
        if (preg_match('/^\d{14}$/', $cleanKey)) {
            return 'cnpj';
        }
        
        // Email
        if (filter_var($cleanKey, FILTER_VALIDATE_EMAIL)) {
            return 'email';
        }
        
        // Telefone (10 ou 11 dígitos)
        if (preg_match('/^\d{10,11}$/', $cleanKey)) {
            return 'telefone';
        }
        
        // Chave aleatória (UUID ou similar)
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $cleanKey)) {
            return 'aleatoria';
        }
        
        // Default para CPF se não conseguir detectar
        return 'cpf';
    }

    /**
     * Obter dados para movimentação interativa (gráfico + cards)
     */
    public function getInteractiveMovement(Request $request)
    {
        try {
            // Pegar usuário do middleware JWT
            $user = $request->user() ?? $request->user_auth;
            
            if (!$user) {
                Log::error('getInteractiveMovement - Usuário não encontrado no request', [
                    'has_user' => !empty($request->user()),
                    'has_user_auth' => !empty($request->user_auth)
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }

            $periodo = $request->input('periodo', 'hoje'); // hoje, ontem, 7dias, 30dias
            
            // Calcular datas baseado no período
            $dates = $this->calculateInteractiveDateRange($periodo);
            
            Log::info('Movimentação Interativa - Filtro aplicado', [
                'periodo' => $periodo,
                'inicio' => $dates['inicio']->format('Y-m-d H:i:s'),
                'fim' => $dates['fim']->format('Y-m-d H:i:s'),
                'user_id' => $user->username
            ]);

            // Cache leve (60s) por usuário + período
            $cacheKey = sprintf('dash:interactive:%s:%s:%s:%s', $user->username, $periodo, $dates['inicio']->format('YmdHis'), $dates['fim']->format('YmdHis'));
            $payload = cache()->remember($cacheKey, 60, function () use ($user, $dates, $periodo) {
                $cardData = $this->getCardDataOptimized($user->username, $dates);
                $chartData = $this->getChartDataOptimized($user->username, $dates, $periodo);
                return [
                    'periodo' => $periodo,
                    'data_inicio' => $dates['inicio']->format('Y-m-d H:i:s'),
                    'data_fim' => $dates['fim']->format('Y-m-d H:i:s'),
                    'cards' => $cardData,
                    'chart' => $chartData,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $payload,
            ])->header('Access-Control-Allow-Origin', '*');

        } catch (\Exception $e) {
            Log::error('Erro ao obter movimentação interativa', [
                'error' => $e->getMessage(),
                'user_id' => $user->username ?? 'unknown',
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500)->header('Access-Control-Allow-Origin', '*');
        }
    }

    /**
     * Calcular intervalo de datas para movimentação interativa
     */
    private function calculateInteractiveDateRange($periodo)
    {
        $now = \Carbon\Carbon::now('America/Sao_Paulo');
        
        switch ($periodo) {
            case 'hoje':
                return [
                    'inicio' => $now->copy()->startOfDay(),
                    'fim' => $now->copy()->endOfDay()
                ];
                
            case 'ontem':
                return [
                    'inicio' => $now->copy()->subDay()->startOfDay(),
                    'fim' => $now->copy()->subDay()->endOfDay()
                ];
                
            case '7dias':
                return [
                    'inicio' => $now->copy()->subDays(6)->startOfDay(),
                    'fim' => $now->copy()->endOfDay()
                ];
                
            case '30dias':
                return [
                    'inicio' => $now->copy()->subDays(29)->startOfDay(),
                    'fim' => $now->copy()->endOfDay()
                ];
                
            default:
                return [
                    'inicio' => $now->copy()->startOfDay(),
                    'fim' => $now->copy()->endOfDay()
                ];
        }
    }

    /**
     * Obter dados dos cards de forma otimizada
     */
    private function getCardDataOptimized($username, $dates)
    {
        // Query otimizada para depósitos
        $depositosData = \App\Models\Solicitacoes::where('user_id', $username)
            ->whereBetween('date', [$dates['inicio'], $dates['fim']])
            ->whereIn('status', ['PAID_OUT', 'COMPLETED'])
            ->selectRaw('COUNT(*) as quantidade, SUM(amount) as total_valor')
            ->first();

        // Query otimizada para saques
        $saquesData = \App\Models\SolicitacoesCashOut::where('user_id', $username)
            ->whereBetween('date', [$dates['inicio'], $dates['fim']])
            ->whereIn('status', ['PAID_OUT', 'COMPLETED'])
            ->selectRaw('COUNT(*) as quantidade, SUM(amount) as total_valor')
            ->first();

        return [
            'total_depositos' => (float) ($depositosData->total_valor ?? 0),
            'qtd_depositos' => (int) ($depositosData->quantidade ?? 0),
            'total_saques' => (float) ($saquesData->total_valor ?? 0),
            'qtd_saques' => (int) ($saquesData->quantidade ?? 0)
        ];
    }

    /**
     * Obter dados do gráfico agrupado por hora (otimizado)
     */
    private function getChartDataOptimized($username, $dates, $periodo)
    {
        // Determinar intervalo de agrupamento baseado no período
        $groupBy = $this->getGroupByInterval($periodo);
        
        // Query otimizada para depósitos agrupados
        $depositosChart = \App\Models\Solicitacoes::where('user_id', $username)
            ->whereBetween('date', [$dates['inicio'], $dates['fim']])
            ->whereIn('status', ['PAID_OUT', 'COMPLETED'])
            ->selectRaw("DATE_FORMAT(date, '{$groupBy}') as periodo, SUM(amount) as valor")
            ->groupBy('periodo')
            ->orderBy('periodo')
            ->get()
            ->keyBy('periodo');

        // Query otimizada para saques agrupados
        $saquesChart = \App\Models\SolicitacoesCashOut::where('user_id', $username)
            ->whereBetween('date', [$dates['inicio'], $dates['fim']])
            ->whereIn('status', ['PAID_OUT', 'COMPLETED'])
            ->selectRaw("DATE_FORMAT(date, '{$groupBy}') as periodo, SUM(amount) as valor")
            ->groupBy('periodo')
            ->orderBy('periodo')
            ->get()
            ->keyBy('periodo');

        // Gerar períodos completos baseado no intervalo
        $periodos = $this->generatePeriods($dates['inicio'], $dates['fim'], $periodo);
        
        $chartData = [];
        foreach ($periodos as $periodoItem) {
            $chartData[] = [
                'periodo' => $periodoItem['label'],
                'depositos' => (float) ($depositosChart->get($periodoItem['key'])->valor ?? 0),
                'saques' => (float) ($saquesChart->get($periodoItem['key'])->valor ?? 0)
            ];
        }

        return $chartData;
    }

    /**
     * Determinar formato de agrupamento baseado no período
     */
    private function getGroupByInterval($periodo)
    {
        switch ($periodo) {
            case 'hoje':
            case 'ontem':
                return '%H:00'; // Agrupar por hora
            case '7dias':
                return '%Y-%m-%d'; // Agrupar por dia
            case '30dias':
                return '%Y-%m-%d'; // Agrupar por dia
            default:
                return '%H:00';
        }
    }

    /**
     * Gerar períodos completos para o gráfico
     */
    private function generatePeriods($inicio, $fim, $periodo)
    {
        $periodos = [];
        
        if ($periodo === 'hoje' || $periodo === 'ontem') {
            // Gerar todas as horas do dia (00:00 a 23:00)
            $current = $inicio->copy();
            while ($current->lte($fim)) {
                $periodos[] = [
                    'key' => $current->format('H:00'),
                    'label' => $current->format('H:i')
                ];
                $current->addHour();
            }
        } else {
            // Gerar todos os dias do período
            $current = $inicio->copy();
            while ($current->lte($fim)) {
                $periodos[] = [
                    'key' => $current->format('Y-m-d'),
                    'label' => $current->format('d/m')
                ];
                $current->addDay();
            }
        }
        
        return $periodos;
    }

    /**
     * Obter estatísticas do dashboard (saldo, entradas, saídas, splits do mês)
     */
    public function getDashboardStats(Request $request)
    {
        try {
            // Pegar usuário do middleware JWT
            $user = $request->user() ?? $request->user_auth;
            
            if (!$user) {
                Log::error('getDashboardStats - Usuário não encontrado no request', [
                    'has_user' => !empty($request->user()),
                    'has_user_auth' => !empty($request->user_auth)
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }

            // Calcular primeiro e último dia do mês atual
            $startOfMonth = \Carbon\Carbon::now()->startOfMonth();
            $endOfMonth = \Carbon\Carbon::now()->endOfMonth();

            $cacheKey = sprintf('dash:stats:%s:%s:%s', $user->username, $startOfMonth->format('Ym'), $endOfMonth->format('Ym'));
            $payload = cache()->remember($cacheKey, 60, function () use ($user, $startOfMonth, $endOfMonth) {
                $saldoDisponivel = $user->saldo ?? 0;
                $entradasMes = \App\Models\Solicitacoes::where('user_id', $user->username)
                    ->whereBetween('date', [$startOfMonth, $endOfMonth])
                    ->whereIn('status', ['PAID_OUT', 'COMPLETED'])
                    ->sum('amount');
                $saidasMes = \App\Models\SolicitacoesCashOut::where('user_id', $user->username)
                    ->whereBetween('date', [$startOfMonth, $endOfMonth])
                    ->whereIn('status', ['PAID_OUT', 'COMPLETED'])
                    ->sum('amount');
                $splitsMes = \App\Models\SplitInternoExecutado::whereHas('splitInterno', function($query) use ($user) {
                        $query->where('usuario_beneficiario_id', $user->id);
                    })
                    ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
                    ->where('status', 'processado')
                    ->sum('valor_split');
                return [
                    'saldo_disponivel' => (float) $saldoDisponivel,
                    'entradas_mes' => (float) $entradasMes,
                    'saidas_mes' => (float) $saidasMes,
                    'splits_mes' => (float) $splitsMes,
                    'periodo' => [
                        'inicio' => $startOfMonth->format('Y-m-d'),
                        'fim' => $endOfMonth->format('Y-m-d'),
                    ],
                ];
            });

            Log::info('Dashboard Stats (com cache)', [
                'user_id' => $user->username,
                'periodo' => $startOfMonth->format('Y-m-d') . ' a ' . $endOfMonth->format('Y-m-d')
            ]);

            return response()->json([
                'success' => true,
                'data' => $payload,
            ])->header('Access-Control-Allow-Origin', '*');

        } catch (\Exception $e) {
            Log::error('Erro ao obter estatísticas do dashboard', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500)->header('Access-Control-Allow-Origin', '*');
        }
    }

    /**
     * Obter resumo de transações (para os 8 cards abaixo do gráfico)
     */
    public function getTransactionSummary(Request $request)
    {
        try {
            // Pegar usuário do middleware JWT
            $user = $request->user() ?? $request->user_auth;
            
            if (!$user) {
                Log::error('getTransactionSummary - Usuário não encontrado no request');
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }

            $periodo = $request->input('periodo', 'hoje'); // hoje, ontem, 7dias, 30dias

            // Calcular datas baseado no período (usando mesma função do gráfico)
            $dates = $this->calculateInteractiveDateRange($periodo);
            
            Log::info('Resumo de Transações - Filtro aplicado', [
                'periodo' => $periodo,
                'inicio' => $dates['inicio']->format('Y-m-d H:i:s'),
                'fim' => $dates['fim']->format('Y-m-d H:i:s'),
                'user_id' => $user->username
            ]);

            $cacheKey = sprintf('dash:summary:%s:%s:%s', $user->username, $periodo, $dates['inicio']->format('YmdHis'));
            $payload = cache()->remember($cacheKey, 60, function () use ($user, $dates) {
                $quantidadeDepositos = \App\Models\Solicitacoes::where('user_id', $user->username)
                    ->whereBetween('date', [$dates['inicio'], $dates['fim']])
                    ->whereIn('status', ['PAID_OUT', 'COMPLETED'])
                    ->count();
                $quantidadeSaques = \App\Models\SolicitacoesCashOut::where('user_id', $user->username)
                    ->whereBetween('date', [$dates['inicio'], $dates['fim']])
                    ->whereIn('status', ['PAID_OUT', 'COMPLETED'])
                    ->count();
                $tarifaCobrada = \App\Models\Solicitacoes::where('user_id', $user->username)
                    ->whereBetween('date', [$dates['inicio'], $dates['fim']])
                    ->whereIn('status', ['PAID_OUT', 'COMPLETED'])
                    ->sum('taxa_cash_in');
                $qrCodesPagos = \App\Models\Solicitacoes::where('user_id', $user->username)
                    ->whereBetween('date', [$dates['inicio'], $dates['fim']])
                    ->whereIn('status', ['PAID_OUT', 'COMPLETED'])
                    ->count();
                $qrCodesGerados = \App\Models\Solicitacoes::where('user_id', $user->username)
                    ->whereBetween('date', [$dates['inicio'], $dates['fim']])
                    ->count();
                $ticketMedioDepositos = \App\Models\Solicitacoes::where('user_id', $user->username)
                    ->whereBetween('date', [$dates['inicio'], $dates['fim']])
                    ->whereIn('status', ['PAID_OUT', 'COMPLETED'])
                    ->avg('amount') ?: 0;
                $ticketMedioSaques = \App\Models\SolicitacoesCashOut::where('user_id', $user->username)
                    ->whereBetween('date', [$dates['inicio'], $dates['fim']])
                    ->whereIn('status', ['PAID_OUT', 'COMPLETED'])
                    ->avg('amount') ?: 0;
                $valorMinDepositos = \App\Models\Solicitacoes::where('user_id', $user->username)
                    ->whereBetween('date', [$dates['inicio'], $dates['fim']])
                    ->whereIn('status', ['PAID_OUT', 'COMPLETED'])
                    ->min('amount') ?: 0;
                $valorMaxDepositos = \App\Models\Solicitacoes::where('user_id', $user->username)
                    ->whereBetween('date', [$dates['inicio'], $dates['fim']])
                    ->whereIn('status', ['PAID_OUT', 'COMPLETED'])
                    ->max('amount') ?: 0;
                $infracoes = \App\Models\Solicitacoes::where('user_id', $user->username)
                    ->whereBetween('date', [$dates['inicio'], $dates['fim']])
                    ->whereIn('status', ['MEDIATION', 'CHARGEBACK', 'DISPUTE'])
                    ->count();
                $valorInfracoes = \App\Models\Solicitacoes::where('user_id', $user->username)
                    ->whereBetween('date', [$dates['inicio'], $dates['fim']])
                    ->whereIn('status', ['MEDIATION', 'CHARGEBACK', 'DISPUTE'])
                    ->sum('amount');
                $qrCodesPagosSafe = max($qrCodesPagos, 1);
                $percentualInfracoes = ($infracoes / $qrCodesPagosSafe) * 100;
                return [
                    'periodo' => $dates['inicio']->format('Y-m-d H:i:s') . ' a ' . $dates['fim']->format('Y-m-d H:i:s'),
                    'quantidadeTransacoes' => [
                        'depositos' => (int) $quantidadeDepositos,
                        'saques' => (int) $quantidadeSaques,
                    ],
                    'tarifaCobrada' => (float) $tarifaCobrada,
                    'qrCodes' => [
                        'pagos' => (int) $qrCodesPagos,
                        'gerados' => (int) $qrCodesGerados,
                    ],
                    'indiceConversao' => 0, // permanecer como antes se calculado em outra parte
                    'ticketMedio' => [
                        'depositos' => (float) $ticketMedioDepositos,
                        'saques' => (float) $ticketMedioSaques,
                    ],
                    'valorMinMax' => [
                        'depositos' => [
                            'min' => (float) $valorMinDepositos,
                            'max' => (float) $valorMaxDepositos,
                        ],
                    ],
                    'infracoes' => (int) $infracoes,
                    'percentualInfracoes' => [
                        'percentual' => (float) number_format($percentualInfracoes, 2, '.', ''),
                        'valorTotal' => (float) $valorInfracoes,
                    ],
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $payload,
            ])->header('Access-Control-Allow-Origin', '*');

        } catch (\Exception $e) {
            Log::error('Erro ao obter resumo de transações', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500)->header('Access-Control-Allow-Origin', '*');
        }
    }

    /**
     * Extrair usuário do request (usando middleware check.token.secret)
     */
    private function getUserFromRequest(Request $request)
    {
        try {
            // O middleware check.token.secret já validou o token e secret
            // e adicionou o usuário ao request
            $token = $request->input('token');
            $secret = $request->input('secret');
            
            if (!$token || !$secret) {
                return null;
            }

            // Buscar as chaves do usuário
            $userKeys = \App\Models\UsersKey::where('token', $token)
                ->where('secret', $secret)
                ->first();
            
            if (!$userKeys) {
                return null;
            }

            // Buscar o usuário
            return User::where('username', $userKeys->user_id)->first();
            
        } catch (\Exception $e) {
            Log::error('Erro ao obter usuário do request', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Extrair usuário do token (método antigo - mantido para compatibilidade)
     */
    private function getUserFromToken(Request $request)
    {
        try {
            $token = $request->bearerToken();
            
            if (!$token) {
                return null;
            }

            $decoded = json_decode(base64_decode($token), true);
            
            if (!$decoded || !isset($decoded['expires_at']) || $decoded['expires_at'] < now()->timestamp) {
                return null;
            }

            return User::where('username', $decoded['user_id'])->first();
            
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Obter dados de gamificação para a Sidebar (otimizado com cache Redis)
     */
    public function getSidebarGamificationData(Request $request)
    {
        try {
            $user = $request->user() ?? $request->user_auth;
            if (!$user) {
                $user = $this->getUserFromToken($request) ?? $this->getUserFromRequest($request);
            }
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }

            // Cache key específica para Sidebar (TTL: 3 minutos - mais frequente)
            $cacheKey = "sidebar_gamification_user_{$user->id}";
            
            // Tentar obter dados do cache Redis primeiro
            $cachedData = \Illuminate\Support\Facades\Cache::remember($cacheKey, 180, function() use ($user) {
                $gamificationData = \App\Helpers\Helper::meuNivel($user);
                
                // Calcular dados específicos para Sidebar
                $currentLevel = $gamificationData['nivel_atual'];
                $nextLevel = $gamificationData['proximo_nivel'];
                
                return [
                    'current_level' => $currentLevel ? $currentLevel->nome : null,
                    'total_deposited' => $gamificationData['total_depositos'],
                    'current_level_max' => $currentLevel ? $currentLevel->maximo : 100000,
                    'next_level' => $nextLevel ? [
                        'name' => $nextLevel->nome,
                        'minimo' => $nextLevel->minimo,
                        'maximo' => $nextLevel->maximo
                    ] : null
                ];
            });
            
            return response()->json([
                'success' => true,
                'data' => $cachedData
            ])->header('Access-Control-Allow-Origin', '*');
            
        } catch (\Exception $e) {
            Log::error('Erro ao obter dados de gamificação da Sidebar', [
                'user_id' => $user->id ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500)->header('Access-Control-Allow-Origin', '*');
        }
    }
    public function getGamificationData(Request $request)
    {
        try {
            $user = $request->user() ?? $request->user_auth;
            if (!$user) {
                $user = $this->getUserFromToken($request) ?? $this->getUserFromRequest($request);
            }
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }

            // Cache key única para o usuário (TTL: 5 minutos)
            $cacheKey = "gamification_data_user_{$user->id}";
            
            // Tentar obter dados do cache Redis primeiro
            $cachedData = \Illuminate\Support\Facades\Cache::remember($cacheKey, 300, function() use ($user) {
                return [
                    'gamification' => \App\Helpers\Helper::meuNivel($user),
                    'levels' => \App\Helpers\Helper::getNiveis()->sortBy('minimo')->values()
                ];
            });
            
            $gamificationData = $cachedData['gamification'];
            $allLevels = $cachedData['levels'];
            
            // Calcular progresso para cada nível
            $achievementTrail = [];
            foreach ($allLevels as $index => $level) {
                $isCurrentLevel = $level->id === ($gamificationData['nivel_atual']->id ?? null);
                $isCompleted = $gamificationData['total_depositos'] >= $level->maximo;
                $isLocked = $gamificationData['total_depositos'] < $level->minimo;
                
                $status = 'Bloqueado';
                $color = 'text-gray-400';
                $bgColor = 'bg-gray-400';
                $progress = 0;
                
                if ($isCompleted) {
                    $status = 'Concluído';
                    $color = 'text-green-500';
                    $bgColor = 'bg-green-500';
                    $progress = 100;
                } else if ($isCurrentLevel) {
                    $status = 'Em progresso';
                    $color = 'text-orange-500';
                    $bgColor = 'bg-orange-500';
                    $progress = min(($gamificationData['total_depositos'] / $level->maximo) * 100, 100);
                }
                
                $achievementTrail[] = [
                    'id' => $level->id,
                    'name' => $level->nome,
                    'amount' => 'R$ ' . number_format($level->maximo, 0, ',', '.'),
                    'status' => $status,
                    'color' => $color,
                    'bgColor' => $bgColor,
                    'progress' => $progress,
                    'minimo' => $level->minimo,
                    'maximo' => $level->maximo,
                    'cor' => $level->cor,
                    'icone' => $level->icone
                ];
            }
            
            // Calcular próximo nível e progresso atual
            $currentLevel = $gamificationData['nivel_atual'];
            $nextLevel = $gamificationData['proximo_nivel'];
            $currentProgress = 0;
            
            if ($currentLevel && $nextLevel) {
                $currentProgress = (($gamificationData['total_depositos'] - $currentLevel->minimo) / 
                    ($nextLevel->minimo - $currentLevel->minimo)) * 100;
            } else if ($currentLevel && !$nextLevel) {
                $currentProgress = 100; // Nível máximo
            }
            
            $response = [
                'success' => true,
                'data' => [
                    'current_level' => $currentLevel ? $currentLevel->nome : null,
                    'total_deposited' => $gamificationData['total_depositos'],
                    'current_progress' => min($currentProgress, 100),
                    'next_level' => $nextLevel ? [
                        'name' => $nextLevel->nome,
                        'minimo' => $nextLevel->minimo,
                        'maximo' => $nextLevel->maximo
                    ] : null,
                    'achievement_trail' => $achievementTrail,
                    'achievement_messages' => [
                        [
                            'level' => 'Bronze',
                            'message' => 'Parabéns! Você deu o primeiro passo na sua jornada. Continue assim e veja sua confiança crescer!',
                            'icon' => '/icons8-medalha-de-terceiro-lugar-48.png'
                        ],
                        [
                            'level' => 'Prata',
                            'message' => 'Excelente evolução! Você está colhendo os frutos do seu esforço. Parabéns pela dedicação!',
                            'icon' => '/icons8-medalha-de-segundo-lugar-80.png'
                        ],
                        [
                            'level' => 'Ouro',
                            'message' => 'Impressionante! Sua persistência está dando resultados. Você está entre os melhores!',
                            'icon' => '/icons8-medalha-de-primeiro-lugar-48.png'
                        ],
                        [
                            'level' => 'Safira',
                            'message' => 'Extraordinário! Você é um vencedor de verdade. Sua determinação inspira outros!',
                            'icon' => '/icons8-logotipo-safira-48.png'
                        ],
                        [
                            'level' => 'Diamante',
                            'message' => 'Parabéns! Você alcançou o ápice da Jornada Orizon! Sua dedicação e excelência são verdadeiramente inspiradoras.',
                            'icon' => '/icons8-diamante-64.png'
                        ]
                    ],
                    'summary_cards' => [
                        'total_deposited' => 'R$ ' . number_format($gamificationData['total_depositos'], 2, ',', '.'),
                        'current_level' => $currentLevel ? $currentLevel->nome : null,
                        'next_goal' => $nextLevel ? 
                            'R$ ' . number_format($currentLevel->maximo - $gamificationData['total_depositos'], 0, ',', '.') : 
                            'Concluído!'
                    ]
                ]
            ];

            Log::info('Dados de gamificação obtidos (com cache Redis)', [
                'user_id' => $user->username,
                'current_level' => $currentLevel ? $currentLevel->nome : null,
                'total_deposited' => $gamificationData['total_depositos'],
                'cache_key' => $cacheKey
            ]);

            return response()->json($response)->header('Access-Control-Allow-Origin', '*');

        } catch (\Exception $e) {
            Log::error('Erro ao obter dados de gamificação', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500)->header('Access-Control-Allow-Origin', '*');
        }
    }
}
