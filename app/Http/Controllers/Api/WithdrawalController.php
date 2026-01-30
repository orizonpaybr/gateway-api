<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Requests\WithdrawalIndexRequest;
use App\Http\Requests\WithdrawalStatsRequest;
use App\Services\WithdrawalStatsService;
use App\Services\TreealService;
use App\Services\BalanceService;
use App\Models\SolicitacoesCashOut;
use App\Models\User;
use App\Models\Treeal;
use App\Helpers\Helper;
use App\Constants\UserPermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class WithdrawalController extends Controller
{
    public function __construct(private readonly WithdrawalStatsService $statsService)
    {
    }

    /**
     * Listar solicitações de saque com filtros e paginação
     */
    public function index(WithdrawalIndexRequest $request)
    {
        try {
            // Inputs saneados (validados pelo FormRequest)
            $validated = $request->validated();

            $perPage = (int) ($validated['limit'] ?? 20);
            $perPage = max(1, min($perPage, 100)); // limites seguros
            $page = max(1, (int) ($validated['page'] ?? 1));

            // CORRIGIDO: Normalizar status antes de validar para tratar 'all' corretamente
            $statusInput = strtolower((string) ($validated['status'] ?? 'pending'));
            $status = match($statusInput) {
                'pending' => 'PENDING',
                'completed' => 'COMPLETED',
                'paid_out' => 'PAID_OUT',
                'cancelled' => 'CANCELLED',
                'failed' => 'FAILED',
                'processing' => 'PROCESSING',
                'all' => 'ALL',
                default => 'PENDING'
            };

            $search = trim((string) ($validated['busca'] ?? ''));
            if (mb_strlen($search) > 100) {
                $search = mb_substr($search, 0, 100);
            }

            $dataInicio = $validated['data_inicio'] ?? null;
            $dataFim = $validated['data_fim'] ?? null;

            $tipo = strtolower((string) ($validated['tipo'] ?? 'all')); // 'manual', 'automatico', 'all'
            $tipo = in_array($tipo, ['manual', 'automatico', 'all']) ? $tipo : 'all';

            // Query base
            // CORRIGIDO: Incluir todos os tipos de saques (WEB, MANUAL, AUTOMATICO) para aparecerem na listagem de aprovação
            $query = SolicitacoesCashOut::query()
                ->with(['user:id,username,email,user_id'])
                ->select([
                    'id','user_id','externalreference','beneficiaryname','beneficiarydocument',
                    'pix','pixkey','amount','taxa_cash_out','cash_out_liquido','status',
                    'executor_ordem','descricao_transacao','date','created_at','updated_at'
                ])
                ->whereIn('descricao_transacao', ['WEB', 'MANUAL', 'AUTOMATICO']);

            // CORRIGIDO: Filtro de status - verificar 'ALL' após normalização
            if ($status && $status !== 'ALL') {
                $query->where('status', $status);
            }

            // CORRIGIDO: Filtro de tipo (manual/automático) - garantir que funciona corretamente
            if ($tipo === 'manual') {
                $query->whereNull('executor_ordem');
            } elseif ($tipo === 'automatico') {
                $query->whereNotNull('executor_ordem');
            }
            // Se $tipo === 'all', não aplica filtro (mostra todos)

            // Filtro de busca (nome, documento, ID)
            if ($search !== '') {
                $query->where(function($q) use ($search) {
                    $q->where('beneficiaryname', 'LIKE', "%{$search}%")
                      ->orWhere('beneficiarydocument', 'LIKE', "%{$search}%")
                      ->orWhere('id', 'LIKE', "%{$search}%")
                      ->orWhere('externalreference', 'LIKE', "%{$search}%")
                      ->orWhereHas('user', function($userQuery) use ($search) {
                          $userQuery->where('username', 'LIKE', "%{$search}%")
                                    ->orWhere('email', 'LIKE', "%{$search}%");
                      });
                });
            }

            // Filtro de data
            if ($dataInicio) {
                $query->whereDate('date', '>=', $dataInicio);
            }
            if ($dataFim) {
                $query->whereDate('date', '<=', $dataFim);
            }

            // Ordenação: mais recentemente atualizados primeiro (aprovações/rejeições sobem para o topo)
            $query->orderByDesc('updated_at')->orderByDesc('id');

            // Paginação
            $saques = $query->paginate($perPage, ['*'], 'page', $page);

            // Formatar dados
            $data = $saques->map(function ($saque) {
                return [
                    'id' => $saque->id,
                    'transaction_id' => $saque->externalreference,
                    'user_id' => $saque->user_id,
                    'username' => $saque->user ? $saque->user->username : 'N/A',
                    'email' => $saque->user ? $saque->user->email : 'N/A',
                    'nome_cliente' => $saque->beneficiaryname,
                    'documento' => $saque->beneficiarydocument,
                    'pix_key' => $saque->pixkey,
                    'pix_type' => $saque->pix,
                    'amount' => (float) $saque->amount,
                    'taxa' => (float) $saque->taxa_cash_out,
                    'valor_liquido' => (float) $saque->cash_out_liquido,
                    'status' => $saque->status,
                    'status_legivel' => $this->getStatusLabel($saque->status),
                    'tipo_processamento' => $saque->executor_ordem ? 'Automático' : 'Manual',
                    'executor' => $saque->executor_ordem,
                    'data' => $saque->date,
                    'created_at' => $saque->created_at,
                    'updated_at' => $saque->updated_at,
                    'descricao' => $saque->descricao_transacao ?? 'Saque PIX',
                    'end_to_end' => $saque->end_to_end ?? null, // Coluna pode não existir em todas as bases
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'data' => $data,
                    'current_page' => $saques->currentPage(),
                    'last_page' => $saques->lastPage(),
                    'per_page' => $saques->perPage(),
                    'total' => $saques->total(),
                    'from' => $saques->firstItem(),
                    'to' => $saques->lastItem(),
                ]
            ], 200);
        } catch (\Exception $e) {
            Log::error('Erro ao listar saques', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao listar saques.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Buscar detalhes de uma solicitação específica
     */
    public function show($id)
    {
        try {
            $saque = SolicitacoesCashOut::with('user')->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $saque->id,
                    'transaction_id' => $saque->externalreference,
                    'id_transaction_gateway' => $saque->idTransaction,
                    'user_id' => $saque->user_id,
                    'username' => $saque->user ? $saque->user->username : 'N/A',
                    'email' => $saque->user ? $saque->user->email : 'N/A',
                    'nome_cliente' => $saque->beneficiaryname,
                    'documento' => $saque->beneficiarydocument,
                    'pix_key' => $saque->pixkey,
                    'pix_type' => $saque->pix,
                    'amount' => (float) $saque->amount,
                    'taxa' => (float) $saque->taxa_cash_out,
                    'valor_liquido' => (float) $saque->cash_out_liquido,
                    'status' => $saque->status,
                    'status_legivel' => $this->getStatusLabel($saque->status),
                    'tipo_processamento' => $saque->executor_ordem ? 'Automático' : 'Manual',
                    'executor' => $saque->executor_ordem,
                    'data' => $saque->date,
                    'created_at' => $saque->created_at,
                    'updated_at' => $saque->updated_at,
                    'descricao' => $saque->descricao_transacao ?? 'Saque PIX',
                    'end_to_end' => $saque->end_to_end ?? null, // Coluna pode não existir em todas as bases
                    'descricao_externa' => $saque->descricao_externa ?? null,
                    'callback' => $saque->callback ?? null,
                    'user_balance' => $saque->user ? (float) $saque->user->saldo : 0,
                ]
            ], 200);
        } catch (\Exception $e) {
            Log::error('Erro ao buscar detalhes do saque', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Saque não encontrado.',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Aprovar uma solicitação de saque
     * CORRIGIDO: Implementação completa de aprovação usando TreealService
     */
    public function approve($id, Request $request)
    {
        try {
            // Verificar se o usuário tem permissão (Admin ou Gerente)
            $user = $request->user();
            if (!in_array($user->permission, [UserPermission::ADMIN, UserPermission::MANAGER], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Você não tem permissão para aprovar saques.'
                ], 403);
            }

            // Buscar saque com lock para evitar processamento duplicado
            $saque = SolicitacoesCashOut::lockForUpdate()->findOrFail($id);

            // Verificar se já foi processado
            if ($saque->status !== 'PENDING') {
                return response()->json([
                    'success' => false,
                    'message' => 'Este saque já foi processado.'
                ], 400);
            }

            // Buscar usuário do saque
            $userSaque = User::where('user_id', $saque->user_id)->first();
            if (!$userSaque) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário do saque não encontrado.'
                ], 404);
            }

            // Verificar saldo suficiente antes de processar
            $valorTotalDescontar = $saque->amount + ($saque->taxa_cash_out ?? 0);
            if ($userSaque->saldo < $valorTotalDescontar) {
                return response()->json([
                    'success' => false,
                    'message' => 'Saldo insuficiente para processar o saque. Saldo disponível: R$ ' . number_format($userSaque->saldo, 2, ',', '.') . ', necessário: R$ ' . number_format($valorTotalDescontar, 2, ',', '.')
                ], 400);
            }

            // Determinar adquirente baseado no executor_ordem ou adquirente padrão
            $adquirente = $saque->executor_ordem ?? Helper::adquirenteDefault();
            
            // Se não tem executor_ordem e adquirente padrão é Treeal, usar Treeal
            if (!$saque->executor_ordem) {
                $adquirente = Helper::adquirenteDefault();
            }

            if (!$adquirente) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nenhum adquirente configurado.'
                ], 500);
            }

            // Processar aprovação baseado no adquirente
            switch (strtolower($adquirente)) {
                case 'treeal':
                    return $this->approveWithTreeal($saque, $userSaque, $valorTotalDescontar);
                
                case 'pagarme':
                    // Pagar.me não suporta saques PIX diretamente
                    return response()->json([
                        'success' => false,
                        'message' => 'Pagar.me não suporta saques PIX. Use Treeal para saques PIX.'
                    ], 500);
                
                default:
                    return response()->json([
                        'success' => false,
                        'message' => 'Adquirente não suportado para aprovação de saques: ' . $adquirente
                    ], 500);
            }

        } catch (\Exception $e) {
            Log::error('Erro ao aprovar saque', [
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao aprovar saque: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Aprovar saque usando Treeal
     */
    private function approveWithTreeal(SolicitacoesCashOut $saque, User $userSaque, float $valorTotalDescontar)
    {
        try {
            $treealService = app(TreealService::class);
            $treealConfig = Treeal::first();

            // Validar se Treeal está configurado e ativo
            if (!$treealConfig || !$treealService->isActive()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Treeal não está configurado ou ativo.'
                ], 500);
            }

            // Gerar idempotency key único
            $idempotencyKey = str()->uuid()->toString();

            // CORRIGIDO: Os campos no banco:
            // - pix = valor da chave PIX (ex: "12345678901", "email@exemplo.com")
            // - pixkey = tipo da chave PIX (ex: "cpf", "email", "telefone")
            // Criar saque na API Treeal
            Log::info('WithdrawalController::approveWithTreeal - Preparando aprovação', [
                'saque_id' => $saque->id,
                'pix_value' => $saque->pix,
                'pix_type' => $saque->pixkey,
                'amount' => $saque->amount,
            ]);
            
            $withdrawalResult = $treealService->createWithdrawalByPixKey(
                (float) $saque->amount,
                $saque->pix, // CORRIGIDO: pix contém o valor da chave PIX
                'Saque aprovado manualmente - ID: ' . $saque->id,
                $idempotencyKey,
                $saque->pixkey // CORRIGIDO: pixkey contém o tipo da chave PIX
            );

            if (!$withdrawalResult['success']) {
                Log::error('WithdrawalController::approveWithTreeal - Erro ao criar saque na API', [
                    'error' => $withdrawalResult['message'] ?? 'Erro desconhecido',
                    'saque_id' => $saque->id
                ]);
                return response()->json([
                    'success' => false,
                    'message' => $withdrawalResult['message'] ?? 'Erro ao processar saque PIX na Treeal'
                ], 500);
            }

            $transactionId = $withdrawalResult['transaction_id'] ?? $withdrawalResult['id'] ?? null;
            $status = $withdrawalResult['status'] ?? 'PROCESSING';

            // Mapear status da Treeal para status interno
            $statusInterno = $this->mapTreealStatusToInternal($status);

            // Atualizar saque em transação para garantir atomicidade
            // IMPORTANTE: O débito é feito aqui na aprovação porque a Treeal já iniciou o processamento
            // Se o webhook chegar depois com LIQUIDATED, o PaymentProcessingService verificará idempotência
            DB::transaction(function () use ($saque, $userSaque, $valorTotalDescontar, $transactionId, $statusInterno) {
                // Lock no saque para evitar race condition
                $saqueAtualizado = SolicitacoesCashOut::where('id', $saque->id)
                    ->lockForUpdate()
                    ->first();
                    
                // Verificar idempotência - não debitar se já foi processado
                if (in_array($saqueAtualizado->status, ['COMPLETED', 'PAID_OUT'])) {
                    Log::info('WithdrawalController::approveWithTreeal - Saque já processado', [
                        'saque_id' => $saque->id,
                        'status' => $saqueAtualizado->status
                    ]);
                    return;
                }
                
                // Atualizar saque
                $saqueAtualizado->update([
                    'status' => $statusInterno,
                    'externalreference' => $transactionId ?? $saqueAtualizado->externalreference,
                    'idTransaction' => $transactionId ?? $saqueAtualizado->idTransaction,
                    'executor_ordem' => 'Treeal', // Marcar como processado pela Treeal
                    'end_to_end' => $transactionId ?? $saqueAtualizado->end_to_end,
                ]);

                // Debitar saldo do usuário (thread-safe)
                $balanceService = app(BalanceService::class);
                $balanceService->decrementBalance($userSaque, $valorTotalDescontar, 'saldo');
                
                // Recalcular saldo líquido
                Helper::calculaSaldoLiquido($userSaque->user_id);
            });

            Log::info('WithdrawalController::approveWithTreeal - Saque aprovado com sucesso', [
                'saque_id' => $saque->id,
                'transaction_id' => $transactionId,
                'status' => $statusInterno,
                'user_id' => $userSaque->user_id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Saque aprovado e processado com sucesso.',
                'data' => [
                    'transaction_id' => $transactionId,
                    'status' => $statusInterno
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('WithdrawalController::approveWithTreeal - Exceção', [
                'saque_id' => $saque->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Mapear status da Treeal para status interno
     */
    private function mapTreealStatusToInternal(string $statusTreeal): string
    {
        $statusNormalizado = strtoupper(trim($statusTreeal));
        
        return match($statusNormalizado) {
            'PROCESSING', 'PENDING' => 'PROCESSING',
            'COMPLETED', 'CONCLUIDO', 'PAID' => 'COMPLETED',
            'FAILED', 'FALHOU', 'ERROR' => 'FAILED',
            'CANCELLED', 'CANCELADO' => 'CANCELLED',
            default => 'PROCESSING'
        };
    }

    /**
     * Rejeitar uma solicitação de saque
     */
    public function reject($id, Request $request)
    {
        try {
            // Verificar se o usuário tem permissão (Admin ou Gerente)
            $user = $request->user();
            if (!in_array($user->permission, [UserPermission::ADMIN, UserPermission::MANAGER], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Você não tem permissão para rejeitar saques.'
                ], 403);
            }

            // Buscar saque
            $saque = SolicitacoesCashOut::findOrFail($id);

            // Verificar se já foi processado
            if ($saque->status !== 'PENDING') {
                return response()->json([
                    'success' => false,
                    'message' => 'Este saque já foi processado.'
                ], 400);
            }

            // Atualizar status
            $saque->status = 'CANCELLED';
            $saque->save();

            // Atualizar usuário (se existir)
            if ($saque->user_id) {
                $userModel = User::where('user_id', $saque->user_id)->first();
                if ($userModel) {
                    $userModel->increment('transacoes_recused', 1);
                    $userModel->decrement('saldo_bloqueado', $saque->amount);
                    $userModel->save();

                    Helper::calculaSaldoLiquido($userModel->user_id);
                } else {
                    Log::warning("Usuário não encontrado ao rejeitar o saque ID: {$saque->id}, user_id: {$saque->user_id}");
                }
            } else {
                // Saque sem usuário associado (pode ocorrer em casos específicos)
                Log::info("Saque rejeitado sem usuário associado - ID: {$saque->id}");
            }

            return response()->json([
                'success' => true,
                'message' => 'Saque rejeitado com sucesso.'
            ], 200);

        } catch (\Exception $e) {
            Log::error('Erro ao rejeitar saque', [
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao rejeitar saque.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obter estatísticas de saques
     */
    public function stats(WithdrawalStatsRequest $request)
    {
        try {
            $periodo = $request->validated()['periodo'] ?? 'hoje';

            $stats = $this->statsService->calculate($periodo);

            return response()->json([
                'success' => true,
                'data' => [
                    'periodo' => $periodo,
                    'data_inicio' => $stats['periodo']['inicio'],
                    'data_fim' => $stats['periodo']['fim'],
                    'total_pendentes' => $stats['totais']['pendentes'],
                    'total_aprovados' => $stats['totais']['aprovados'],
                    'total_rejeitados' => $stats['totais']['rejeitados'],
                    'valor_total' => (float) $stats['valores']['total'],
                    'valor_aprovado' => (float) $stats['valores']['aprovado'],
                    'saques_manuais' => $stats['tipos']['manuais'],
                    'saques_automaticos' => $stats['tipos']['automaticos'],
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Erro ao buscar estatísticas de saques', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao buscar estatísticas.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obter label legível do status
     */
    private function getStatusLabel($status)
    {
        $labels = [
            'PENDING' => 'Pendente',
            'COMPLETED' => 'Concluído',
            'PAID_OUT' => 'Pago',
            'CANCELLED' => 'Cancelado',
            'FAILED' => 'Falhou',
            'PROCESSING' => 'Processando',
        ];

        return $labels[$status] ?? $status;
    }

    /**
     * Obter data inicial baseada no período
     */
    private function getDataInicioPeriodo($periodo)
    {
        switch ($periodo) {
            case 'hoje':
                return now()->startOfDay();
            case '7d':
                return now()->subDays(7)->startOfDay();
            case '30d':
                return now()->subDays(30)->startOfDay();
            case 'mes':
                return now()->startOfMonth();
            default:
                return now()->startOfDay();
        }
    }

    /**
     * Obter configurações de saque
     */
    public function getConfig(Request $request)
    {
        try {
            // Usar cache para reduzir I/O em configurações globais
            $config = Cache::remember('app_settings', 300, function () {
                return \App\Models\App::first();
            });
            
            // Se não existir configuração, retornar valores padrão
            if (!$config) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'saque_automatico' => false,
                        'limite_saque_automatico' => null,
                    ]
                ], 200);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'saque_automatico' => (bool) $config->saque_automatico,
                    // Mapear 0 (persistido para bases NOT NULL) para null (sem limite)
                    'limite_saque_automatico' => ($config->limite_saque_automatico === 0 || $config->limite_saque_automatico === '0.00')
                        ? null
                        : (float) $config->limite_saque_automatico,
                ]
            ], 200);
        } catch (\Exception $e) {
            Log::error('Erro ao buscar configurações de saque', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao buscar configurações.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Atualizar configurações de saque
     */
    public function updateConfig(Request $request)
    {
        try {
            // Verificar se o usuário tem permissão (Apenas Admin)
            $user = $request->user();
            if ($user->permission != UserPermission::ADMIN) {
                return response()->json([
                    'success' => false,
                    'message' => 'Você não tem permissão para atualizar configurações.'
                ], 403);
            }

            $request->validate([
                'saque_automatico' => 'required|boolean',
                'limite_saque_automatico' => 'nullable|numeric|min:0'
            ]);

            // Buscar configuração existente
            $config = \App\Models\App::first();
            
            // Se não existir, criar registro básico
            if (!$config) {
                try {
                    $config = \App\Models\App::create([
                        'saque_automatico' => false,
                        'limite_saque_automatico' => null,
                    ]);
                } catch (\Exception $e) {
                    Log::error('Erro ao criar configuração de app', [
                        'error' => $e->getMessage()
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'Erro ao criar configurações. Verifique se a tabela app existe e tem os campos necessários.',
                        'error' => $e->getMessage()
                    ], 500);
                }
            }

            // Interpretar vazio como NULL (sem limite)
            $limiteRaw = $request->input('limite_saque_automatico');
            $limite = null;
            if ($limiteRaw !== null && $limiteRaw !== '') {
                $limite = (float) str_replace(',', '.', $limiteRaw);
            }

            // Algumas bases têm a coluna como NOT NULL. Usar 0.00 como 'sem limite' e mapear para null no GET.
            $config->update([
                'saque_automatico' => $request->input('saque_automatico'),
                'limite_saque_automatico' => $limite === null ? 0 : $limite
            ]);

            // Limpar cache de configurações
            \Illuminate\Support\Facades\Cache::forget('app_settings');

            return response()->json([
                'success' => true,
                'message' => 'Configurações de saque atualizadas com sucesso!',
                'data' => [
                    'saque_automatico' => (bool) $config->saque_automatico,
                    'limite_saque_automatico' => $config->limite_saque_automatico,
                ]
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro de validação',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Erro ao atualizar configurações de saque', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao atualizar configurações.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

