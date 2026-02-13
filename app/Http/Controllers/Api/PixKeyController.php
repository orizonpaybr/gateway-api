<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PixKeyResource;
use App\Models\App;
use App\Models\PixKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;

class PixKeyController extends Controller
{
    /**
     * Listar todas as chaves PIX do usuário
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user() ?? $request->user_auth;
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }

            // Buscar com cache
            $keys = PixKey::getUserKeys($user->username);

            return response()->json([
                'success' => true,
                'data' => PixKeyResource::collection($keys)
            ])->header('Access-Control-Allow-Origin', '*');

        } catch (\Exception $e) {
            Log::error('Erro ao listar chaves PIX', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao listar chaves PIX'
            ], 500)->header('Access-Control-Allow-Origin', '*');
        }
    }

    /**
     * Criar nova chave PIX
     */
    public function store(Request $request)
    {
        try {
            $user = $request->user() ?? $request->user_auth;
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }

            // Validação
            $validator = Validator::make($request->all(), [
                'key_type' => 'required|in:cpf,cnpj,telefone,email,aleatoria',
                'key_value' => 'required|string',
                'key_label' => 'nullable|string|max:100',
                'is_default' => 'nullable|boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 400)->header('Access-Control-Allow-Origin', '*');
            }

            $keyType = $request->key_type;
            $keyValue = $request->key_value;

            // Limpar a chave (remover formatação)
            $cleanKey = preg_replace('/[^0-9a-zA-Z@.-]/', '', $keyValue);

            // Validar formato da chave
            if (!PixKey::validateKeyFormat($keyType, $keyValue)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Formato de chave PIX inválido para o tipo selecionado'
                ], 400)->header('Access-Control-Allow-Origin', '*');
            }

            // Verificar se já existe
            $existingKey = PixKey::where('user_id', $user->username)
                ->where('key_value', $cleanKey)
                ->first();

            if ($existingKey) {
                return response()->json([
                    'success' => false,
                    'message' => 'Esta chave PIX já está cadastrada'
                ], 409)->header('Access-Control-Allow-Origin', '*');
            }

            // Verificar limite de chaves (máximo 5 por usuário)
            $userKeysCount = PixKey::forUser($user->username)->active()->count();
            if ($userKeysCount >= 5) {
                return response()->json([
                    'success' => false,
                    'message' => 'Você atingiu o limite máximo de 5 chaves PIX cadastradas'
                ], 400)->header('Access-Control-Allow-Origin', '*');
            }

            // Se for a primeira chave, marcar como padrão automaticamente
            $isFirstKey = $userKeysCount === 0;
            $isDefault = $isFirstKey || ($request->is_default === true);

            // Criar chave
            $pixKey = PixKey::create([
                'user_id' => $user->username,
                'key_type' => $keyType,
                'key_value' => $cleanKey,
                'key_label' => $request->key_label,
                'is_active' => true,
                'is_default' => $isDefault,
                'verified_at' => now() // Auto-verificar por enquanto
            ]);

            Log::info('Chave PIX criada', [
                'user_id' => $user->username,
                'key_id' => $pixKey->id,
                'key_type' => $keyType
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Chave PIX cadastrada com sucesso',
                'data' => new PixKeyResource($pixKey)
            ], 201)->header('Access-Control-Allow-Origin', '*');

        } catch (\Exception $e) {
            Log::error('Erro ao criar chave PIX', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao cadastrar chave PIX'
            ], 500)->header('Access-Control-Allow-Origin', '*');
        }
    }

    /**
     * Exibir uma chave PIX específica
     */
    public function show(Request $request, $id)
    {
        try {
            $user = $request->user() ?? $request->user_auth;
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }

            $pixKey = PixKey::forUser($user->username)->find($id);

            if (!$pixKey) {
                return response()->json([
                    'success' => false,
                    'message' => 'Chave PIX não encontrada'
                ], 404)->header('Access-Control-Allow-Origin', '*');
            }

            return response()->json([
                'success' => true,
                'data' => new PixKeyResource($pixKey)
            ])->header('Access-Control-Allow-Origin', '*');

        } catch (\Exception $e) {
            Log::error('Erro ao buscar chave PIX', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao buscar chave PIX'
            ], 500)->header('Access-Control-Allow-Origin', '*');
        }
    }

    /**
     * Atualizar chave PIX
     */
    public function update(Request $request, $id)
    {
        try {
            $user = $request->user() ?? $request->user_auth;
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }

            $pixKey = PixKey::forUser($user->username)->find($id);

            if (!$pixKey) {
                return response()->json([
                    'success' => false,
                    'message' => 'Chave PIX não encontrada'
                ], 404)->header('Access-Control-Allow-Origin', '*');
            }

            // Validação
            $validator = Validator::make($request->all(), [
                'key_label' => 'nullable|string|max:100',
                'is_default' => 'nullable|boolean',
                'is_active' => 'nullable|boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 400)->header('Access-Control-Allow-Origin', '*');
            }

            // Atualizar campos permitidos
            if ($request->has('key_label')) {
                $pixKey->key_label = $request->key_label;
            }

            if ($request->has('is_default')) {
                $pixKey->is_default = $request->is_default;
            }

            if ($request->has('is_active')) {
                $pixKey->is_active = $request->is_active;
            }

            $pixKey->save();

            Log::info('Chave PIX atualizada', [
                'user_id' => $user->username,
                'key_id' => $pixKey->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Chave PIX atualizada com sucesso',
                'data' => new PixKeyResource($pixKey)
            ])->header('Access-Control-Allow-Origin', '*');

        } catch (\Exception $e) {
            Log::error('Erro ao atualizar chave PIX', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao atualizar chave PIX'
            ], 500)->header('Access-Control-Allow-Origin', '*');
        }
    }

    /**
     * Deletar chave PIX
     */
    public function destroy(Request $request, $id)
    {
        try {
            $user = $request->user() ?? $request->user_auth;
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }

            $pixKey = PixKey::forUser($user->username)->find($id);

            if (!$pixKey) {
                return response()->json([
                    'success' => false,
                    'message' => 'Chave PIX não encontrada'
                ], 404)->header('Access-Control-Allow-Origin', '*');
            }

            // Se for a chave padrão e houver outras, marcar a próxima como padrão
            if ($pixKey->is_default) {
                $nextKey = PixKey::forUser($user->username)
                    ->active()
                    ->where('id', '!=', $id)
                    ->first();

                if ($nextKey) {
                    $nextKey->is_default = true;
                    $nextKey->save();
                }
            }

            $pixKey->delete();

            Log::info('Chave PIX deletada', [
                'user_id' => $user->username,
                'key_id' => $id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Chave PIX removida com sucesso'
            ])->header('Access-Control-Allow-Origin', '*');

        } catch (\Exception $e) {
            Log::error('Erro ao deletar chave PIX', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao remover chave PIX'
            ], 500)->header('Access-Control-Allow-Origin', '*');
        }
    }

    /**
     * Definir chave como padrão
     */
    public function setDefault(Request $request, $id)
    {
        try {
            $user = $request->user() ?? $request->user_auth;
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }

            $pixKey = PixKey::forUser($user->username)->active()->find($id);

            if (!$pixKey) {
                return response()->json([
                    'success' => false,
                    'message' => 'Chave PIX não encontrada'
                ], 404)->header('Access-Control-Allow-Origin', '*');
            }

            $pixKey->is_default = true;
            $pixKey->save();

            return response()->json([
                'success' => true,
                'message' => 'Chave padrão definida com sucesso'
            ])->header('Access-Control-Allow-Origin', '*');

        } catch (\Exception $e) {
            Log::error('Erro ao definir chave padrão', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao definir chave padrão'
            ], 500)->header('Access-Control-Allow-Origin', '*');
        }
    }

    /**
     * Realizar saque com chave PIX
     */
    public function withdraw(Request $request)
    {
        try {
            $user = $request->user() ?? $request->user_auth;
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }

            // Verificar se usuário está aprovado (status = ACTIVE e não banido)
            if (!\App\Helpers\UserStatusHelper::isApproved($user)) {
                Log::warning('Tentativa de saque PIX com conta não aprovada', [
                    'username' => $user->username,
                    'status' => $user->status,
                    'banido' => $user->banido ?? false,
                    'ip' => $request->ip()
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Sua conta precisa estar aprovada para realizar saques PIX. Entre em contato com o suporte.'
                ], 403)->header('Access-Control-Allow-Origin', '*');
            }

            $validator = Validator::make($request->all(), [
                'key_id' => 'nullable|exists:pix_keys,id',
                'key_type' => 'required_without:key_id|in:cpf,cnpj,telefone,email,aleatoria',
                'key_value' => 'required_without:key_id|string',
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

            $amount = $request->amount;

            // Verificar se o saque está bloqueado para este usuário
            if ($user->saque_bloqueado ?? false) {
                Log::warning('Tentativa de saque bloqueado via PixKeyController', [
                    'user_id' => $user->id,
                    'username' => $user->username,
                    'ip' => $request->ip()
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Saque bloqueado para este usuário. Entre em contato com o suporte.'
                ], 403)->header('Access-Control-Allow-Origin', '*');
            }

            // Configurações do app (para cálculo de taxas)
            $setting = \Illuminate\Support\Facades\Cache::remember('app_settings', 300, function () {
                return App::first();
            });
            if (!$setting) {
                return response()->json([
                    'success' => false,
                    'message' => 'Configurações do aplicativo não encontradas.'
                ], 500)->header('Access-Control-Allow-Origin', '*');
            }

            // Calcular taxas (taxa Orizon + custo Treeal)
            $isInterfaceWeb = true;
            $taxaCalculada = \App\Helpers\TaxaSaqueHelper::calcularTaxaSaque((float) $amount, $setting, $user, $isInterfaceWeb);
            $taxaCashOut = $taxaCalculada['taxa_cash_out'];           // Taxa total cobrada do cliente
            $taxaAplicacao = $taxaCalculada['taxa_aplicacao'];        // Lucro Orizon
            $taxaAdquirente = $taxaCalculada['taxa_adquirente'];      // Custo Treeal
            $cashOutLiquido = $taxaCalculada['saque_liquido'];        // Valor que o cliente recebe
            $valorTotalDescontar = $taxaCalculada['valor_total_descontar']; // Total a debitar do saldo

            // Verificar saldo total disponível (saldo principal + saldo de afiliados)
            $balanceService = app(\App\Services\BalanceService::class);
            $saldoTotalDisponivel = $balanceService->getTotalAvailableBalance($user);
            
            if ($saldoTotalDisponivel < $valorTotalDescontar) {
                return response()->json([
                    'success' => false,
                    'message' => 'Saldo insuficiente. Disponível: R$ ' . number_format($saldoTotalDisponivel, 2, ',', '.') . ', Necessário: R$ ' . number_format($valorTotalDescontar, 2, ',', '.') . ' (valor R$ ' . number_format($amount, 2, ',', '.') . ' + taxa R$ ' . number_format($taxaCashOut, 2, ',', '.') . ')'
                ], 400)->header('Access-Control-Allow-Origin', '*');
            }

            // Obter chave PIX
            if ($request->has('key_id')) {
                $pixKey = PixKey::forUser($user->username)->active()->find($request->key_id);
                
                if (!$pixKey) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Chave PIX não encontrada'
                    ], 404)->header('Access-Control-Allow-Origin', '*');
                }

                $keyValue = $pixKey->key_value;
                $keyType = $pixKey->key_type;
            } else {
                // Chave informada manualmente
                $keyValue = preg_replace('/[^0-9a-zA-Z@.-]/', '', $request->key_value);
                $keyType = $request->key_type;

                // Validar formato
                if (!PixKey::validateKeyFormat($keyType, $request->key_value)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Formato de chave PIX inválido'
                    ], 400)->header('Access-Control-Allow-Origin', '*');
                }
            }

            // Obter adquirente padrão
            $adquirenteDefault = \App\Helpers\Helper::adquirenteDefault($user->username ?? $user->user_id, 'pix');
            
            if (!$adquirenteDefault) {
                Log::error('Nenhum adquirente PIX configurado', [
                    'user_id' => $user->username
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Nenhum adquirente PIX configurado. Entre em contato com o suporte.'
                ], 503)->header('Access-Control-Allow-Origin', '*');
            }
            
            // Verificar se o saque deve ser processado automaticamente ou manualmente
            $processarAutomatico = false;
            if ($setting->saque_automatico) {
                // Se saque_automatico está ativo, verificar limite
                $temLimite = !is_null($setting->limite_saque_automatico) && (float)$setting->limite_saque_automatico > 0;
                $dentroDoLimite = !$temLimite || ((float)$amount <= (float)$setting->limite_saque_automatico);
                $processarAutomatico = $dentroDoLimite;
            }
            
            Log::info('Realizando saque PIX com chave', [
                'user_id' => $user->username,
                'amount' => $amount,
                'key_type' => $keyType,
                'key_value' => $keyValue,
                'adquirente' => $adquirenteDefault,
                'processamento' => $processarAutomatico ? 'AUTOMATICO' : 'MANUAL',
                'saque_automatico_config' => $setting->saque_automatico,
                'limite_config' => $setting->limite_saque_automatico
            ]);

            // Regra abaixo: APENAS saque MANUAL. Débito na criação; em rejeição, valor + taxa são devolvidos.
            // Saque automático é processado na hora no bloco seguinte (Treeal + débito, sem aprovação).
            if (!$processarAutomatico) {
                $idempotencyKey = uniqid('withdraw_manual_', true);
                $description = $request->description ?? 'Saque via PIX';
                
                // Criar registro de saque pendente
                $withdrawal = \App\Models\SolicitacoesCashOut::create([
                    'user_id' => $user->user_id ?? $user->username,
                    'externalreference' => $idempotencyKey,
                    'amount' => $amount,
                    'beneficiaryname' => $user->name ?? 'Não informado',
                    'beneficiarydocument' => $user->cpf_cnpj ?? '',
                    'pix' => $keyValue,
                    'pixkey' => $keyType,
                    'idTransaction' => $idempotencyKey,
                    'status' => 'PENDING',
                    'type' => 'PIX',
                    'date' => now(),
                    'taxa_cash_out' => $taxaCashOut,
                    'cash_out_liquido' => $cashOutLiquido,
                    'descricao_transacao' => 'MANUAL',
                    'executor_ordem' => null,
                ]);
                
                // Debitar do saldo combinado (saldo_afiliado primeiro, depois saldo)
                $balanceService = app(\App\Services\BalanceService::class);
                $balanceService->decrementCombinedBalance($user, $valorTotalDescontar);
                \App\Helpers\Helper::calculaSaldoLiquido($user->user_id ?? $user->username);
                
                Log::info('Saque PIX manual criado - pendente de aprovação (valor + taxa debitados)', [
                    'withdrawal_id' => $withdrawal->id,
                    'user_id' => $user->username,
                    'amount' => $amount,
                    'valor_total_descontar' => $valorTotalDescontar,
                    'motivo' => !$setting->saque_automatico
                        ? 'Saque automático desativado'
                        : 'Valor acima do limite de R$ ' . number_format($setting->limite_saque_automatico, 2, ',', '.')
                ]);
                
                return response()->json([
                    'success' => true,
                    'message' => 'Saque criado com sucesso e aguardando aprovação manual.',
                    'data' => [
                        'transaction_id' => $idempotencyKey,
                        'withdrawal_id' => $withdrawal->id,
                        'amount' => $amount,
                        'key_type' => $keyType,
                        'key_value' => $keyValue,
                        'description' => $description,
                        'status' => 'PENDING_APPROVAL',
                        'tipo_processamento' => 'Manual',
                        'motivo_manual' => !$setting->saque_automatico 
                            ? 'Saque automático desativado no sistema' 
                            : 'Valor acima do limite automático de R$ ' . number_format($setting->limite_saque_automatico, 2, ',', '.'),
                        'created_at' => now()->toISOString(),
                        // Split de taxas
                        'taxa_cash_out' => round($taxaCashOut, 2),
                        'taxa_adquirente' => round($taxaAdquirente, 2),
                        'taxa_aplicacao' => round($taxaAplicacao, 2),
                        'valor_liquido' => round($cashOutLiquido, 2),
                        'valor_total_descontar' => round($valorTotalDescontar, 2),
                        'observacao' => 'Valor e taxa já foram descontados. Em caso de rejeição, serão devolvidos.'
                    ]
                ])->header('Access-Control-Allow-Origin', '*');
            }

            // Saque AUTOMÁTICO: processado na hora (Treeal + débito), sem aprovação.
            if ($adquirenteDefault === 'treeal') {
                try {
                    $treealService = app(\App\Services\TreealService::class);
                    
                    if (!$treealService->isActive()) {
                        throw new \Exception('Adquirente Treeal não está configurada ou ativa');
                    }
                    
                    $idempotencyKey = uniqid('withdraw_auto_', true);
                    $description = $request->description ?? 'Saque via PIX';
                    
                    // Criar saque via Treeal
                    $treealResponse = $treealService->createWithdrawalByPixKey(
                        $amount,
                        $keyValue,
                        $description,
                        $idempotencyKey,
                        $keyType
                    );
                    
                    // Registrar transação no banco
                    $withdrawal = \App\Models\SolicitacoesCashOut::create([
                        'user_id' => $user->user_id ?? $user->username,
                        'externalreference' => $idempotencyKey,
                        'amount' => $amount,
                        'beneficiaryname' => $user->name ?? 'Não informado',
                        'beneficiarydocument' => $user->cpf_cnpj ?? '',
                        'pix' => $keyValue,
                        'pixkey' => $keyType,
                        'idTransaction' => $treealResponse['paymentId'] ?? $idempotencyKey,
                        'status' => 'PROCESSING',
                        'type' => 'PIX',
                        'date' => now(),
                        'taxa_cash_out' => $taxaCashOut,
                        'cash_out_liquido' => $cashOutLiquido,
                        'descricao_transacao' => 'AUTOMATICO',
                        'executor_ordem' => 'Treeal',
                    ]);
                    
                    // Debitar saldo do usuário
                    $user->saldo -= $valorTotalDescontar;
                    $user->save();
                    
                    Log::info('Saque PIX automático criado com sucesso via Treeal', [
                        'withdrawal_id' => $withdrawal->id,
                        'transaction_id' => $treealResponse['paymentId'] ?? $idempotencyKey,
                        'user_id' => $user->username
                    ]);
                    
                    return response()->json([
                        'success' => true,
                        'message' => 'Saque PIX realizado com sucesso',
                        'data' => [
                            'transaction_id' => $treealResponse['paymentId'] ?? $idempotencyKey,
                            'amount' => $amount,
                            'key_type' => $keyType,
                            'key_value' => $keyValue,
                            'description' => $description,
                            'status' => 'PROCESSING',
                            'tipo_processamento' => 'Automático',
                            'estimated_time' => '5-10 minutos',
                            'created_at' => now()->toISOString(),
                            'adquirente' => 'treeal',
                            // Split de taxas
                            'taxa_cash_out' => round($taxaCashOut, 2),
                            'taxa_adquirente' => round($taxaAdquirente, 2),
                            'taxa_aplicacao' => round($taxaAplicacao, 2),
                            'valor_liquido' => round($cashOutLiquido, 2),
                            'valor_total_descontado' => round($valorTotalDescontar, 2)
                        ]
                    ])->header('Access-Control-Allow-Origin', '*');
                    
                } catch (\Exception $e) {
                    Log::error('Erro ao processar saque automático via Treeal', [
                        'error' => $e->getMessage(),
                        'user_id' => $user->username,
                        'amount' => $amount
                    ]);
                    
                    return response()->json([
                        'success' => false,
                        'message' => 'Erro ao processar saque PIX: ' . $e->getMessage()
                    ], 500)->header('Access-Control-Allow-Origin', '*');
                }
            }

        } catch (\Exception $e) {
            Log::error('Erro ao realizar saque PIX', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao processar saque PIX'
            ], 500)->header('Access-Control-Allow-Origin', '*');
        }
    }
}

