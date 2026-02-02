<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\AffiliateCommission;
use App\Constants\UserStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;

class UserController extends Controller
{
    private const CACHE_PREFIX_PROFILE = 'user_profile_';
    private const CACHE_PREFIX_BALANCE = 'user_balance_';
    private const CACHE_TTL = 300;

    /**
     * Limpar cache do usuário de forma centralizada
     * 
     * @param string $username
     * @return void
     */
    private function clearUserCache(string $username): void
    {
        Cache::forget(self::CACHE_PREFIX_PROFILE . $username);
        Cache::forget(self::CACHE_PREFIX_BALANCE . $username);
    }

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
                $totalInflows = \App\Models\Solicitacoes::where('user_id', $user->user_id)
                    ->whereIn('status', ['PAID_OUT', 'COMPLETED'])
                    ->sum('amount');

                // Calcular totais de saques (saídas) - apenas COMPLETED e PAID_OUT
                $totalOutflows = \App\Models\SolicitacoesCashOut::where('user_id', $user->user_id)
                    ->whereIn('status', ['PAID_OUT', 'COMPLETED'])
                    ->sum('amount');

                return [
                    'totalInflows' => $totalInflows,
                    'totalOutflows' => $totalOutflows
                ];
            });

            $totalInflows = $balanceData['totalInflows'];
            $totalOutflows = $balanceData['totalOutflows'];

            // Arredondar todos os valores monetários para 2 casas decimais (evita problemas de precisão de ponto flutuante)
            $currentBalance = round((float) ($user->saldo ?? 0), 2);
            $totalInflowsRounded = round((float) $totalInflows, 2);
            $totalOutflowsRounded = round((float) $totalOutflows, 2);

            // Log para debug
            Log::info('Saldo calculado', [
                'user_id' => $user->username,
                'saldo_atual' => $currentBalance,
                'total_inflows' => $totalInflowsRounded,
                'total_outflows' => $totalOutflowsRounded,
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
                    'current' => $currentBalance,
                    'totalInflows' => number_format($totalInflowsRounded, 2, '.', ''),
                    'totalOutflows' => number_format($totalOutflowsRounded, 2, '.', ''),
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
                $depositosQuery = \App\Models\Solicitacoes::where('user_id', $user->user_id)
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
            $saquesQuery = \App\Models\SolicitacoesCashOut::where('user_id', $user->user_id)
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
                // Garantir que as datas tenham hora para whereBetween funcionar corretamente
                // Se a data vem apenas como YYYY-MM-DD, adicionar horas
                $dataInicioFormatada = strlen($dataInicio) === 10 
                    ? $dataInicio . ' 00:00:00' 
                    : $dataInicio;
                $dataFimFormatada = strlen($dataFim) === 10 
                    ? $dataFim . ' 23:59:59' 
                    : $dataFim;
                
                $depositosQuery->whereBetween('date', [$dataInicioFormatada, $dataFimFormatada]);
                $saquesQuery->whereBetween('date', [$dataInicioFormatada, $dataFimFormatada]);
            }

            // Aplicar filtro de status se fornecido
            if ($status) {
                $depositosQuery->where('status', $status);
                $saquesQuery->where('status', $status);
            }

            // Aplicar filtro de busca se fornecido
            if ($busca && trim($busca) !== '') {
                $this->applyTransactionsSearchFilter($depositosQuery, $saquesQuery, trim($busca));
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
                    'externalreference' => $transaction->externalreference ?? null,
                    'tipo' => $transaction->tipo ?? 'deposito',
                    'amount' => (float) ($transaction->amount ?? 0),
                    'valor_liquido' => (float) ($transaction->valor_liquido ?? 0),
                    'taxa' => (float) ($transaction->taxa ?? 0),
                    'status' => $transaction->status ?? 'PENDING',
                    'status_legivel' => $this->getStatusLegivel($transaction->status ?? 'PENDING'),
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
                        'status_legivel' => $this->getStatusLegivel($deposito->status),
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
                        'status_legivel' => $this->getStatusLegivel($saque->status),
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
            // Obter usuário do middleware verify.jwt
            $user = $request->user() ?? $request->input('user_auth');
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }

            // Verificar se usuário está aprovado (status = ACTIVE e não banido)
            if (!\App\Helpers\UserStatusHelper::isApproved($user)) {
                Log::warning('Tentativa de gerar QR Code PIX com conta não aprovada', [
                    'username' => $user->username,
                    'status' => $user->status,
                    'banido' => $user->banido ?? false,
                    'ip' => $request->ip()
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Sua conta precisa estar aprovada para gerar QR Codes PIX. Entre em contato com o suporte.'
                ], 403)->header('Access-Control-Allow-Origin', '*');
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

            // Adquirentes removidos - apenas Pagar.me disponível (apenas para cartão)
            return response()->json([
                'success' => false,
                'message' => 'Geração de QR Code PIX não disponível. Use Pagar.me para pagamentos com cartão.'
            ], 503)->header('Access-Control-Allow-Origin', '*');

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

            // Verificar se o saque está bloqueado para este usuário
            if ($user->saque_bloqueado ?? false) {
                Log::warning('Tentativa de saque bloqueado via API', [
                    'user_id' => $user->id,
                    'username' => $user->username,
                    'ip' => $request->ip()
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Saque bloqueado para este usuário. Entre em contato com o suporte.'
                ], 403)->header('Access-Control-Allow-Origin', '*');
            }

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

            // Adquirentes removidos - apenas Pagar.me disponível (apenas para cartão)
            return response()->json([
                'success' => false,
                'message' => 'Saque PIX não disponível. Adquirentes PIX foram removidos.'
            ], 503)->header('Access-Control-Allow-Origin', '*');

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
     * Obter extrato completo com paginação e cache Redis
     */
    public function getExtrato(Request $request)
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

            // Parâmetros de paginação
            $page = max((int) $request->get('page', 1), 1);
            $limit = min((int) $request->get('limit', 20), 100); // Máximo 100 por página
            
            // Parâmetros de filtro
            $periodo = $request->get('periodo'); // null se não enviado (significa "Todos")
            $dataInicio = $request->get('data_inicio');
            $dataFim = $request->get('data_fim');
            $busca = trim($request->get('busca', ''));
            $tipo = $request->get('tipo'); // 'entrada', 'saida', ou null (todos)

            // Determinar se deve aplicar filtro de data
            $hasDateFilter = !empty($periodo) || !empty($dataInicio) || !empty($dataFim);
            
            // Se não há período nem datas específicas, buscar todas as transações (sem filtro de data)
            if (!$hasDateFilter) {
                $periodo = null;
                $dataInicio = null;
                $dataFim = null;
            } else {
                // Se há período mas não há datas específicas, calcular datas do período
                if (!empty($periodo) && empty($dataInicio) && empty($dataFim)) {
                    $dates = $this->calculateInteractiveDateRange($periodo);
                    $dataInicio = $dates['inicio']->format('Y-m-d H:i:s');
                    $dataFim = $dates['fim']->format('Y-m-d H:i:s');
                }
            }

            // Cache Redis para extrato (TTL: 3 minutos)
            $cacheKey = sprintf(
                'extrato:%s:%d:%d:%s:%s:%s:%s:%s',
                $user->username,
                $page,
                $limit,
                $periodo ?: 'all',
                $dataInicio ?: 'null',
                $dataFim ?: 'null',
                md5($busca ?: ''),
                $tipo ?: 'all'
            );

            $extratoData = \Illuminate\Support\Facades\Cache::remember($cacheKey, 180, function() use ($user, $page, $limit, $periodo, $dataInicio, $dataFim, $busca, $tipo, $hasDateFilter) {
                
                // Buscar dados de entradas (depósitos) - apenas COMPLETED e PAID_OUT
                $entradasQuery = \App\Models\Solicitacoes::where('user_id', $user->username)
                    ->whereIn('status', ['PAID_OUT', 'COMPLETED']);
                
                // Aplicar filtro de data apenas se houver período ou datas específicas
                if ($hasDateFilter && $dataInicio && $dataFim) {
                    $entradasQuery->whereBetween('date', [$dataInicio, $dataFim]);
                }

                // Aplicar filtro de busca se fornecido
                if ($busca) {
                    $entradasQuery->where(function($query) use ($busca) {
                        $query->where('transaction_id', 'like', "%{$busca}%")
                              ->orWhere('nome_cliente', 'like', "%{$busca}%")
                              ->orWhere('documento', 'like', "%{$busca}%")
                              ->orWhere('descricao_transacao', 'like', "%{$busca}%")
                              ->orWhere('descricao', 'like', "%{$busca}%");
                        
                        // Buscar por valor - tentar diferentes interpretações do número
                        $valorBusca = preg_replace('/[^0-9,.]/', '', $busca);
                        $valorBusca = str_replace(',', '.', $valorBusca);
                        
                        if (is_numeric($valorBusca) && $valorBusca > 0) {
                            $valorNumerico = (float) $valorBusca;
                            
                            // Se o número não tem ponto decimal e é grande, tentar diferentes posições
                            if (strpos($busca, '.') === false && strpos($busca, ',') === false && $valorNumerico >= 100) {
                                // Tentar com 2 casas decimais (mais comum para valores monetários)
                                $valorComDecimais = $valorNumerico / 100;
                                $query->orWhere(function($q) use ($valorComDecimais, $valorNumerico) {
                                    // Buscar valor exato ou com 2 casas decimais
                                    $q->whereBetween('amount', [$valorComDecimais * 0.999, $valorComDecimais * 1.001])
                                      ->orWhereBetween('deposito_liquido', [$valorComDecimais * 0.999, $valorComDecimais * 1.001])
                                      // Também tentar valor sem decimais (para valores inteiros)
                                      ->orWhereBetween('amount', [$valorNumerico * 0.999, $valorNumerico * 1.001])
                                      ->orWhereBetween('deposito_liquido', [$valorNumerico * 0.999, $valorNumerico * 1.001]);
                                });
                            } else {
                                // Valor com ponto decimal ou valor pequeno - busca normal
                                $query->orWhere(function($q) use ($valorNumerico) {
                                    $q->whereBetween('amount', [$valorNumerico * 0.999, $valorNumerico * 1.001])
                                      ->orWhereBetween('deposito_liquido', [$valorNumerico * 0.999, $valorNumerico * 1.001]);
                                });
                            }
                        }
                    });
                }

                // Buscar dados de saídas (saques) - apenas COMPLETED e PAID_OUT
                $saidasQuery = \App\Models\SolicitacoesCashOut::where('user_id', $user->username)
                    ->whereIn('status', ['PAID_OUT', 'COMPLETED']);
                
                // Aplicar filtro de data apenas se houver período ou datas específicas
                if ($hasDateFilter && $dataInicio && $dataFim) {
                    $saidasQuery->whereBetween('date', [$dataInicio, $dataFim]);
                }

                // Aplicar filtro de busca se fornecido
                if ($busca) {
                    $saidasQuery->where(function($query) use ($busca) {
                        $query->where('transaction_id', 'like', "%{$busca}%")
                              ->orWhere('nome_cliente', 'like', "%{$busca}%")
                              ->orWhere('documento', 'like', "%{$busca}%")
                              ->orWhere('descricao_transacao', 'like', "%{$busca}%")
                              ->orWhere('descricao', 'like', "%{$busca}%");
                        
                        // Buscar por valor - tentar diferentes interpretações do número
                        $valorBusca = preg_replace('/[^0-9,.]/', '', $busca);
                        $valorBusca = str_replace(',', '.', $valorBusca);
                        
                        if (is_numeric($valorBusca) && $valorBusca > 0) {
                            $valorNumerico = (float) $valorBusca;
                            
                            // Se o número não tem ponto decimal e é grande, tentar diferentes posições
                            if (strpos($busca, '.') === false && strpos($busca, ',') === false && $valorNumerico >= 100) {
                                // Tentar com 2 casas decimais (mais comum para valores monetários)
                                $valorComDecimais = $valorNumerico / 100;
                                $query->orWhere(function($q) use ($valorComDecimais, $valorNumerico) {
                                    // Buscar valor exato ou com 2 casas decimais
                                    $q->whereBetween('amount', [$valorComDecimais * 0.999, $valorComDecimais * 1.001])
                                      ->orWhereBetween('cash_out_liquido', [$valorComDecimais * 0.999, $valorComDecimais * 1.001])
                                      // Também tentar valor sem decimais (para valores inteiros)
                                      ->orWhereBetween('amount', [$valorNumerico * 0.999, $valorNumerico * 1.001])
                                      ->orWhereBetween('cash_out_liquido', [$valorNumerico * 0.999, $valorNumerico * 1.001]);
                                });
                            } else {
                                // Valor com ponto decimal ou valor pequeno - busca normal
                                $query->orWhere(function($q) use ($valorNumerico) {
                                    $q->whereBetween('amount', [$valorNumerico * 0.999, $valorNumerico * 1.001])
                                      ->orWhereBetween('cash_out_liquido', [$valorNumerico * 0.999, $valorNumerico * 1.001]);
                                });
                            }
                        }
                    });
                }

                // Buscar transações para o extrato
                $transacoesEntradas = $entradasQuery->orderBy('date', 'desc')->get();
                $transacoesSaidas = $saidasQuery->orderBy('date', 'desc')->get();
                
                // Combinar e ordenar transações
                $extrato = collect();
                
                // Adicionar entradas com tipo 'entrada'
                foreach ($transacoesEntradas as $entrada) {
                    $extrato->push([
                        'id' => $entrada->id,
                        'transaction_id' => $entrada->idTransaction ?? $entrada->externalreference ?? 'N/A',
                        'tipo' => 'entrada',
                        'valor' => (float) $entrada->amount,
                        'valor_liquido' => (float) $entrada->deposito_liquido,
                        'taxa' => (float) $entrada->taxa_cash_in,
                        'status' => $entrada->status,
                        'status_legivel' => $this->getStatusLegivel($entrada->status),
                        'data' => $entrada->date,
                        'created_at' => $entrada->created_at,
                        'nome_cliente' => $entrada->client_name ?? 'Cliente',
                        'documento' => $entrada->client_document ?? '00000000000',
                        'adquirente' => $entrada->adquirente_ref ?? 'Sistema',
                        'end_to_end' => $entrada->end_to_end ?? null,
                    ]);
                }
                
                // Adicionar saídas com tipo 'saida'
                foreach ($transacoesSaidas as $saida) {
                    $extrato->push([
                        'id' => $saida->id,
                        'transaction_id' => $saida->idTransaction ?? $saida->externalreference ?? 'N/A',
                        'tipo' => 'saida',
                        'valor' => (float) $saida->amount,
                        'valor_liquido' => (float) $saida->cash_out_liquido,
                        'taxa' => (float) $saida->taxa_cash_out,
                        'status' => $saida->status,
                        'status_legivel' => $this->getStatusLegivel($saida->status),
                        'data' => $saida->date,
                        'created_at' => $saida->created_at,
                        'nome_cliente' => $saida->beneficiaryname ?? 'Cliente',
                        'documento' => $saida->beneficiarydocument ?? '00000000000',
                        'adquirente' => $saida->executor_ordem ?? 'Sistema',
                        'end_to_end' => $saida->end_to_end ?? null,
                    ]);
                }

                // Calcular totais baseados em TODAS as transações filtradas (ANTES de aplicar filtro de tipo)
                // Isso garante que os totais sempre reflitam todas as transações do período, independente do filtro de tipo
                $totalEntradas = $extrato->where('tipo', 'entrada')->sum('valor_liquido');
                $totalEntradasLiquidas = $totalEntradas;
                $totalTaxasEntradas = $extrato->where('tipo', 'entrada')->sum('taxa');

                $totalSaidas = $extrato->where('tipo', 'saida')->sum('valor_liquido');
                $totalSaidasLiquidas = $totalSaidas;
                $totalTaxasSaidas = $extrato->where('tipo', 'saida')->sum('taxa');

                // Aplicar filtro de tipo se especificado (após calcular totais)
                if ($tipo === 'entrada') {
                    $extrato = $extrato->where('tipo', 'entrada');
                } elseif ($tipo === 'saida') {
                    $extrato = $extrato->where('tipo', 'saida');
                }

                // Ordenar por data decrescente
                $extrato = $extrato->sortByDesc('data')->values();

                // Aplicar paginação
                $totalItems = $extrato->count();
                $totalPages = ceil($totalItems / $limit);
                $offset = ($page - 1) * $limit;
                $paginatedExtrato = $extrato->slice($offset, $limit)->values();

                return [
                    'data' => $paginatedExtrato,
                    'current_page' => $page,
                    'last_page' => $totalPages,
                    'per_page' => $limit,
                    'total' => $totalItems,
                    'from' => $offset + 1,
                    'to' => min($offset + $limit, $totalItems),
                    'resumo' => [
                        'total_entradas' => $extrato->where('tipo', 'entrada')->sum('valor'),
                        'total_entradas_liquidas' => $totalEntradasLiquidas,
                        'total_taxas_entradas' => $totalTaxasEntradas,
                        'total_saidas' => $extrato->where('tipo', 'saida')->sum('valor'),
                        'total_saidas_liquidas' => $totalSaidasLiquidas,
                        'total_taxas_saidas' => $totalTaxasSaidas,
                        'saldo_atual' => $user->saldo ?? 0,
                        'saldo_periodo' => $totalEntradasLiquidas - $totalSaidasLiquidas,
                    ],
                    'periodo' => $periodo ?: 'all',
                    'data_inicio' => $dataInicio ? (is_string($dataInicio) ? substr($dataInicio, 0, 10) : (\Carbon\Carbon::parse($dataInicio)->format('Y-m-d'))) : null,
                    'data_fim' => $dataFim ? (is_string($dataFim) ? substr($dataFim, 0, 10) : (\Carbon\Carbon::parse($dataFim)->format('Y-m-d'))) : null,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $extratoData
            ])->header('Access-Control-Allow-Origin', '*');

        } catch (\Exception $e) {
            Log::error('Erro ao obter extrato', [
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
     * Obter status legível para transações
     */
    private function getStatusLegivel($status)
    {
        $statusMap = [
            'COMPLETED' => 'Concluída',
            'PAID_OUT' => 'Pago',
            'PENDING' => 'Pendente',
            'FAILED' => 'Falhou',
            'CANCELLED' => 'Cancelada',
            'PROCESSING' => 'Processando',
        ];

        return $statusMap[$status] ?? ucfirst(strtolower($status));
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
                $splitsMes = \App\Models\SplitInternoExecutado::where('usuario_beneficiario_id', $user->id)
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
                // CORRIGIDO: Calcular índice de conversão corretamente
                $indiceConversao = $qrCodesGerados > 0 
                    ? ($qrCodesPagos / $qrCodesGerados) * 100 
                    : 0;
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
                    'indiceConversao' => (float) round($indiceConversao, 2), // CORRIGIDO: Calcular corretamente
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
                        'next_goal' => $this->calculateNextGoal($currentLevel, $nextLevel, $gamificationData['total_depositos'])
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

    /**
     * Calcula a próxima meta de forma correta
     * 
     * @deprecated Use GamificationService::calculateNextGoal() instead
     * 
     * @param object|null $currentLevel
     * @param object|null $nextLevel
     * @param float $totalDeposited
     * @return string
     */
    private function calculateNextGoal($currentLevel, $nextLevel, $totalDeposited): string
    {
        return app(\App\Services\GamificationService::class)
            ->calculateNextGoal($currentLevel, $nextLevel, $totalDeposited);
    }

    /**
     * Trocar senha do usuário
     * 
     * Validações:
     * - Rate Limiting: 3 tentativas por hora
     * - 2FA obrigatório: Verifica se 2FA está ativado
     * - Verifica senha atual com hash bcrypt
     * - Valida força da nova senha
     * - Invalida todas as sessões ao trocar senha
     * - Registra auditoria
     * 
     * Performance:
     * - Usa Redis para rate limiting e invalidação de sessão
     * - Cache invalidado para dados do usuário
     */
    public function changePassword(Request $request)
    {
        try {
            $user = $request->user() ?? $request->user_auth;
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }

            // 🔐 RATE LIMITING: 3 tentativas por hora
            $rateLimitKey = "change_password_attempts_{$user->id}";
            $attempts = Cache::get($rateLimitKey, 0);

            if ($attempts >= 3) {
                Log::warning('Rate limit excedido para trocar senha', [
                    'username' => $user->username,
                    'ip' => $request->ip(),
                    'attempts' => $attempts
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Você excedeu o limite de tentativas. Tente novamente em 1 hora.',
                    'retry_after' => 3600
                ], 429)->header('Access-Control-Allow-Origin', '*');
            }

            // Validar dados de entrada
            // PIN é obrigatório APENAS se 2FA está ativado
            $rules = [
                'current_password' => 'required|string|min:6',
                'new_password' => [
                    'required',
                    'string',
                    'min:8',
                    'confirmed', // new_password === new_password_confirmation
                    'different:current_password', // Nova senha diferente da atual
                    'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/', // Mínimo: 1 minúscula, 1 maiúscula, 1 dígito
                ],
            ];

            // Se 2FA está ativado, PIN é obrigatório
            if ($user->twofa_enabled) {
                $rules['twofa_pin'] = 'required|string|size:6|regex:/^\d+/';
            } else {
                // Se 2FA está desativado, PIN é opcional (será ignorado)
                $rules['twofa_pin'] = 'nullable|string';
            }

            $validator = Validator::make($request->all(), $rules, [
                'twofa_pin.required' => 'PIN de 2FA é obrigatório para trocar senha.',
                'twofa_pin.size' => 'PIN deve ter exatamente 6 dígitos.',
                'twofa_pin.regex' => 'PIN deve conter apenas dígitos.',
                'new_password.regex' => 'A senha deve conter letras maiúsculas, minúsculas e números.',
                'new_password.different' => 'A nova senha não pode ser igual à senha atual.',
                'new_password.confirmed' => 'As senhas não conferem.',
            ]);

            if ($validator->fails()) {
                // Incrementar tentativas (apenas falhas de validação)
                Cache::put($rateLimitKey, $attempts + 1, 3600);

                return response()->json([
                    'success' => false,
                    'message' => 'Validação falhou',
                    'errors' => $validator->errors()
                ], 422)->header('Access-Control-Allow-Origin', '*');
            }

            // 🔐 Verificar PIN de 2FA APENAS se está ativado
            if ($user->twofa_enabled) {
                if (!Hash::check($request->input('twofa_pin'), $user->twofa_pin)) {
                    // Incrementar tentativas (falha de autenticação)
                    Cache::put($rateLimitKey, $attempts + 1, 3600);

                    Log::warning('PIN 2FA incorreto ao trocar senha', [
                        'username' => $user->username,
                        'ip' => $request->ip(),
                        'attempts' => $attempts + 1
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'PIN de 2FA inválido'
                    ], 401)->header('Access-Control-Allow-Origin', '*');
                }
            }

            // 🔐 Verificar se a senha atual está correta
            if (!Hash::check($request->input('current_password'), $user->password)) {
                // Incrementar tentativas
                Cache::put($rateLimitKey, $attempts + 1, 3600);

                Log::warning('Tentativa de trocar senha com senha atual incorreta', [
                    'username' => $user->username,
                    'ip' => $request->ip(),
                    'attempts' => $attempts + 1
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Senha atual incorreta'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }

            // ✅ Atualizar senha com hash bcrypt
            $user->password = Hash::make($request->input('new_password'));
            $user->save();

            // Invalidar todas as sessões do usuário no Redis (força logout em todos os dispositivos)
            $this->invalidateAllUserSessions($user->id);

            // Invalidar cache do usuário de forma centralizada
            $this->clearUserCache($user->username);

            // 🎯 Limpar rate limit após sucesso (reset para próxima hora)
            Cache::forget($rateLimitKey);

            // Registrar auditoria
            Log::info('Senha alterada com sucesso (com 2FA)', [
                'username' => $user->username,
                'ip' => $request->ip(),
                'timestamp' => now(),
                'user_id' => $user->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Senha alterada com sucesso. Você será desconectado.',
            ])->header('Access-Control-Allow-Origin', '*');

        } catch (\Exception $e) {
            Log::error('Erro ao trocar senha', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $user->id ?? null
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500)->header('Access-Control-Allow-Origin', '*');
        }
    }

    /**
     * Invalida todas as sessões do usuário no Redis
     * Força logout em todos os dispositivos
     * 
     * Performance: O(1) com Redis
     */
    private function invalidateAllUserSessions($userId)
    {
        try {
            // Chave Redis para armazenar timestamp de invalidação de sessão
            $invalidationKey = "user_session_invalidate_{$userId}";
            
            // Definir timestamp atual como limite de invalidação
            // Qualquer token emitido ANTES deste timestamp é inválido
            Cache::put(
                $invalidationKey,
                now()->timestamp,
                24 * 60 * 60 // 24 horas
            );

            Log::info('Todas as sessões do usuário foram invalidadas', [
                'user_id' => $userId
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao invalidar sessões', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Obter perfil completo do usuário
     */
    public function getProfile(Request $request)
    {
        try {
            $user = $request->user() ?? $request->user_auth;
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }

            // Verificar se usuário pode fazer login
            if (!\App\Helpers\UserStatusHelper::canLogin($user)) {
                \Illuminate\Support\Facades\Log::warning('Tentativa de acessar perfil com conta inativa/banida', [
                    'username' => $user->username,
                    'status' => $user->status,
                    'banido' => $user->banido,
                    'ip' => $request->ip()
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Sua conta foi desativada ou bloqueada. Entre em contato com o suporte.'
                ], 403)->header('Access-Control-Allow-Origin', '*');
            }

            // Cache Redis para dados do perfil (TTL: 5 minutos)
            $cacheKey = self::CACHE_PREFIX_PROFILE . $user->username;
            $profileData = \Illuminate\Support\Facades\Cache::remember($cacheKey, self::CACHE_TTL, function() use ($user) {
                // Calcular informações derivadas (tipo PF/PJ e status legível)
                $doc = preg_replace('/\D/', '', (string) ($user->cpf_cnpj ?? ''));
                $tipoPessoa = ($doc && strlen($doc) > 11) ? 'PJ' : 'PF';
                $tipoPessoaLegivel = $tipoPessoa === 'PJ' ? 'Pessoa Jurídica' : 'Pessoa Física';
                // Usar UserStatusHelper para obter o status correto (distinguir Pendente de Inativo)
                $statusAtual = \App\Helpers\UserStatusHelper::getStatusText($user);

                // Obter taxas (personalizadas ou globais)
                $taxes = $this->getUserTaxes($user);

                return [
                    'id' => $user->username,
                    'username' => $user->username,
                    'email' => $user->email ?? '',
                    'name' => $user->name ?? $user->username,
                    'gender' => $user->gender ?? null,
                    // Campo de permissão necessário para exibir recursos de administrador no frontend
                    'permission' => $user->permission ?? null,
                    'phone' => $user->telefone ?? '',
                    'cnpj' => $user->cpf_cnpj ?? '',
                    'status' => $user->status == UserStatus::ACTIVE ? 'active' : ($user->status == UserStatus::PENDING ? 'pending' : 'inactive'),
                    'status_numeric' => $user->status,
                    'balance' => $user->saldo ?? 0,
                    'agency' => $user->agency ?? '',
                    'status_text' => $statusAtual,
                    'company' => [
                        'razao_social' => $user->razao_social ?? null,
                        'nome_fantasia' => $user->nome_fantasia ?? null,
                        'tipo_pessoa' => $tipoPessoa,
                        'tipo' => $tipoPessoaLegivel,
                        'area_atuacao' => $user->area_atuacao ?? null,
                    ],
                    'contacts' => [
                        'telefone_principal' => $user->telefone ?? null,
                        'email_principal' => $user->email ?? null,
                    ],
                    'taxes' => $taxes,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $profileData
            ])->header('Access-Control-Allow-Origin', '*');

        } catch (\Exception $e) {
            Log::error('Erro ao obter perfil', [
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
     * Obter dados reais de faturamento e transações
     * Usa calculateInteractiveDateRange para períodos simples
     */
    public function getRealData(Request $request)
    {
        try {
            $user = $request->user() ?? $request->user_auth;
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }

            $periodo = $request->input('periodo', 'hoje'); // hoje, ontem, 7dias, 30dias

            // Calcular datas usando o novo método otimizado
            $dates = $this->calculateInteractiveDateRange($periodo);
            
            Log::info('Dados Reais - Filtro aplicado', [
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
            
            // Combinar e ordenar transações
            $extrato = collect();
            
            // Adicionar entradas
            foreach ($transacoesEntradas as $entrada) {
                $extrato->push([
                    'id' => $entrada->id,
                    'tipo' => 'deposit',
                    'transaction_id' => $entrada->idTransaction ?? $entrada->externalreference,
                    'valor' => $entrada->amount,
                    'valor_liquido' => $entrada->deposito_liquido,
                    'taxa' => $entrada->taxa_cash_in,
                    'status' => $this->getStatusLegivel($entrada->status),
                    'data' => $entrada->date,
                    'nome' => $entrada->client_name ?? 'Cliente',
                    'documento' => $entrada->client_document ?? '00000000000',
                    'adquirente' => $entrada->adquirente ?? 'Sistema'
                ]);
            }

            // Adicionar saídas
            foreach ($transacoesSaidas as $saida) {
                $extrato->push([
                    'id' => $saida->id,
                    'tipo' => 'withdraw',
                    'transaction_id' => $saida->idTransaction ?? $saida->externalreference,
                    'valor' => $saida->amount,
                    'valor_liquido' => $saida->cash_out_liquido,
                    'taxa' => $saida->taxa_cash_out,
                    'status' => $this->getStatusLegivel($saida->status),
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
     * Aplicar filtro de busca para transações pendentes
     * Busca por: descrição e valor
     *
     * @param \Illuminate\Database\Eloquent\Builder $depositosQuery
     * @param \Illuminate\Database\Eloquent\Builder $saquesQuery
     * @param string $busca
     * @return void
     */
    /**
     * Aplicar filtro de busca em transações (depósitos e saques)
     * Busca por: transaction_id, idTransaction, descrição, nome_cliente e valor
     */
    private function applyTransactionsSearchFilter($depositosQuery, $saquesQuery, string $busca): void
    {
        $buscaLower = strtolower(trim($busca));
        $searchPattern = '%' . $buscaLower . '%';

        // Aplicar busca em depósitos
        $depositosQuery->where(function($query) use ($searchPattern, $busca) {
            // Buscar por transaction_id ou idTransaction
            $query->whereRaw('LOWER(idTransaction) LIKE ?', [$searchPattern])
                  ->orWhereRaw('LOWER(externalreference) LIKE ?', [$searchPattern])
                  // Buscar por nome do cliente
                  ->orWhereRaw('LOWER(CAST(client_name AS CHAR CHARACTER SET utf8mb4)) LIKE ?', [$searchPattern])
                  // Buscar por descrição (case-insensitive)
                  ->orWhereRaw('LOWER(CAST(descricao_transacao AS CHAR CHARACTER SET utf8mb4)) LIKE ?', [$searchPattern]);

            // Buscar por valor
            $this->applyValueSearch($query, $busca, ['amount', 'deposito_liquido']);
        });

        // Aplicar busca em saques
        $saquesQuery->where(function($query) use ($searchPattern, $busca) {
            // Buscar por transaction_id ou idTransaction
            $query->whereRaw('LOWER(idTransaction) LIKE ?', [$searchPattern])
                  ->orWhereRaw('LOWER(externalreference) LIKE ?', [$searchPattern])
                  // Buscar por nome do beneficiário
                  ->orWhereRaw('LOWER(CAST(beneficiaryname AS CHAR CHARACTER SET utf8mb4)) LIKE ?', [$searchPattern])
                  // Buscar por descrição (case-insensitive)
                  ->orWhereRaw('LOWER(CAST(descricao_transacao AS CHAR CHARACTER SET utf8mb4)) LIKE ?', [$searchPattern]);

            // Buscar por valor
            $this->applyValueSearch($query, $busca, ['amount', 'cash_out_liquido']);
        });
    }

    /**
     * @deprecated Use applyTransactionsSearchFilter instead
     */
    private function applyPendingTransactionsSearchFilter($depositosQuery, $saquesQuery, string $busca): void
    {
        $this->applyTransactionsSearchFilter($depositosQuery, $saquesQuery, $busca);
    }

    /**
     * Aplicar busca por valor numérico com diferentes interpretações
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $busca
     * @param array $fields Campos numéricos para buscar
     * @return void
     */
    private function applyValueSearch($query, string $busca, array $fields): void
    {
        $valorBusca = preg_replace('/[^0-9,.]/', '', $busca);
        $valorBusca = str_replace(',', '.', $valorBusca);

        if (!is_numeric($valorBusca) || $valorBusca <= 0) {
            return;
        }

        $valorNumerico = (float) $valorBusca;
        $hasDecimalSeparator = strpos($busca, '.') !== false || strpos($busca, ',') !== false;

        $query->orWhere(function($q) use ($valorNumerico, $fields, $hasDecimalSeparator) {
            // Se não tem separador decimal e é um número grande, tentar interpretar como centavos
            if (!$hasDecimalSeparator && $valorNumerico >= 100) {
                $valorComDecimais = $valorNumerico / 100;
                foreach ($fields as $field) {
                    $q->orWhereBetween($field, [$valorComDecimais * 0.999, $valorComDecimais * 1.001])
                      ->orWhereBetween($field, [$valorNumerico * 0.999, $valorNumerico * 1.001]);
                }
            } else {
                // Busca normal com margem de erro de 0.1%
                foreach ($fields as $field) {
                    $q->orWhereBetween($field, [$valorNumerico * 0.999, $valorNumerico * 1.001]);
                }
            }
        });
    }

    /**
     * Obter taxas do usuário (personalizadas ou globais)
     * Prioriza taxas personalizadas se ativas, caso contrário usa taxas globais
     *
     * @param \App\Models\User $user
     * @return array
     */
    private function getUserTaxes($user): array
    {
        $setting = \App\Models\App::first();
        
        if (!$setting) {
            // Retornar valores padrão se não houver configurações
            return $this->getDefaultTaxesStructure();
        }

        // Verificar se usuário tem taxas personalizadas ativas
        $hasPersonalizedTaxes = $user->taxas_personalizadas_ativas ?? false;

        // Taxas de Depósito (Cash In)
        $depositTaxes = $this->getDepositTaxes($user, $setting, $hasPersonalizedTaxes);

        // Taxas de Saque (Cash Out)
        $withdrawTaxes = $this->getWithdrawTaxes($user, $setting, $hasPersonalizedTaxes);

        // Taxas de Afiliado (sempre globais)
        $affiliateTaxes = $this->getAffiliateTaxes($setting);

        return [
            'deposit' => $depositTaxes,
            'withdraw' => $withdrawTaxes,
            'affiliate' => $affiliateTaxes,
        ];
    }

    /**
     * Obter taxas de depósito (personalizadas ou globais)
     * Sistema simplificado: apenas taxa fixa em centavos
     *
     * @param \App\Models\User $user
     * @param \App\Models\App $setting
     * @param bool $hasPersonalizedTaxes
     * @return array
     */
    private function getDepositTaxes($user, $setting, bool $hasPersonalizedTaxes): array
    {
        if ($hasPersonalizedTaxes) {
            // Taxa fixa personalizada do usuário
            $fixed = (float) ($user->taxa_fixa_deposito ?? $setting->taxa_fixa_padrao ?? 0);
        } else {
            // Taxa fixa global do sistema
            $fixed = (float) ($setting->taxa_fixa_padrao ?? 0);
        }

        return [
            'fixed' => $fixed,
        ];
    }

    /**
     * Obter taxas de saque (personalizadas ou globais)
     * Sistema simplificado: apenas taxa fixa em centavos
     *
     * @param \App\Models\User $user
     * @param \App\Models\App $setting
     * @param bool $hasPersonalizedTaxes
     * @return array
     */
    private function getWithdrawTaxes($user, $setting, bool $hasPersonalizedTaxes): array
    {
        if ($hasPersonalizedTaxes) {
            // Taxa fixa personalizada do usuário
            $fixed = (float) ($user->taxa_fixa_pix ?? $setting->taxa_fixa_pix ?? 0);
        } else {
            // Taxa fixa global do sistema
            $fixed = (float) ($setting->taxa_fixa_pix ?? 0);
        }

        return [
            'dashboard' => [
                'fixed' => $fixed,
            ],
            'api' => [
                'fixed' => $fixed,
            ],
        ];
    }

    /**
     * Obter taxas de afiliado (sempre globais)
     *
     * @param \App\Models\App $setting
     * @return array
     */
    private function getAffiliateTaxes($setting): array
    {
        // Taxas de afiliado são sempre globais (gerente_percentage)
        return [
            'fixed' => 0,
            'percent' => (float) ($setting->gerente_percentage ?? 0),
        ];
    }

    /**
     * Retornar estrutura padrão de taxas quando não há configurações
     * Sistema simplificado: apenas taxas fixas em centavos
     *
     * @return array
     */
    private function getDefaultTaxesStructure(): array
    {
        return [
            'deposit' => [
                'fixed' => 0,
            ],
            'withdraw' => [
                'dashboard' => [
                    'fixed' => 0,
                ],
                'api' => [
                    'fixed' => 0,
                ],
            ],
            'affiliate' => [
                'fixed' => 0,
                'percent' => 0,
            ],
        ];
    }

    /**
     * Gerar ou obter link de afiliado do usuário
     * Qualquer usuário pode gerar um link de afiliado
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function generateAffiliateLink(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }

            // Gerar código único se não existe
            if (!$user->affiliate_code) {
                $codigoBase = strtoupper(substr($user->user_id, 0, 4));
                $numeroAleatorio = rand(1000, 9999);
                $codigoCompleto = $codigoBase . $numeroAleatorio;
                
                // Verificar unicidade
                while (User::where('affiliate_code', $codigoCompleto)->where('id', '!=', $user->id)->exists()) {
                    $numeroAleatorio = rand(1000, 9999);
                    $codigoCompleto = $codigoBase . $numeroAleatorio;
                }
                
                $user->affiliate_code = $codigoCompleto;
                $user->affiliate_link = config('app.url') . '/register?ref=' . $user->affiliate_code;
                $user->save();
            }
            
            return response()->json([
                'success' => true,
                'data' => [
                    'affiliate_code' => $user->affiliate_code,
                    'affiliate_link' => $user->affiliate_link
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao gerar link de afiliado', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao gerar link de afiliado'
            ], 500);
        }
    }

    /**
     * Visualizar comissões de afiliados recebidas pelo usuário
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAffiliateCommissions(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }

            $commissions = \App\Models\AffiliateCommission::where('affiliate_id', $user->id)
                ->where('status', 'paid')
                ->with(['user:id,user_id,username,name', 'solicitacao:id,idTransaction,amount', 'solicitacaoCashOut:id,idTransaction,amount'])
                ->orderBy('created_at', 'desc')
                ->paginate(20);
            
            return response()->json([
                'success' => true,
                'data' => $commissions
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao buscar comissões de afiliados', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao buscar comissões de afiliados'
            ], 500);
        }
    }
}
