<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Log, Cache};
use App\Enums\PixKeyType;
use App\Traits\IPManagementTrait;
use App\Models\User;
use App\Models\App;
use App\Helpers\Helper;
use App\Models\Adquirente;
use App\Models\SolicitacoesCashOut;
use App\Helpers\ApiResponseStandardizer;
use App\Services\TreealService;
use App\Models\Treeal;
use Carbon\Carbon;

class SaqueController extends Controller
{
    public function makePayment(Request $request)
    {
        // Verificar se o usuário está autenticado
        $user = $request->user();
        Log::info('SaqueController - Verificação de usuário', [
            'user_from_request' => $user ? 'Presente' : 'Ausente',
            'user_id' => $user ? $user->id : 'N/A',
            'request_data' => $request->all()
        ]);
        
        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'Usuário não autenticado'], 401);
        }
        
        Helper::calculaSaldoLiquido($user->user_id);
        
        // Cache para configurações do app (TTL: 5 minutos)
        $setting = Cache::remember('app_settings', 300, function () {
            return App::first();
        });
        
        if (!$setting) {
            return response()->json(['status' => 'error', 'message' => 'Configurações do aplicativo não encontradas.'], 500);
        }

        // Cache para adquirente padrão do usuário (TTL: 10 minutos)
        $cacheKey = "user_default_acquirer_{$user->user_id}";
        $default = Cache::remember($cacheKey, 600, function () use ($user) {
            return Helper::adquirenteDefault($user->user_id);
        });
        
        if (!$default) {
            return response()->json(['status' => 'error', 'message' => 'Nenhum adquirente configurado.'], 500);
        }

        // Verificar se o saque está bloqueado para este usuário (sem query adicional)
        if ($user->saque_bloqueado ?? false) {
            Log::warning('Tentativa de saque bloqueado', [
                'user_id' => $user->id,
                'username' => $user->username,
                'ip' => $request->ip()
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Saque bloqueado para este usuário. Entre em contato com o suporte.'
            ], 403);
        }

        // Determinar se é saque via interface web ou API
        $isInterfaceWeb = $request->input('baasPostbackUrl') === 'web';
        
        // Debug: Log da requisição
        Log::info('[IP_CHECK] Debug da requisição', [
            'user_id' => $user->user_id,
            'baasPostbackUrl' => $request->input('baasPostbackUrl'),
            'is_interface_web' => $isInterfaceWeb
        ]);
        
        // Nota: A verificação de IP é feita pelo middleware CheckAllowedIP

        // Verificar saldo disponível (considerando valores em mediação)
        $saldoDisponivel = (float) $user->saldo;
        
        // Calcular valores bloqueados em mediação
        $valoresEmMediacao = \App\Models\Solicitacoes::where('user_id', $user->id)
            ->where('status', 'MEDIATION')
            ->sum('deposito_liquido');
        
        $saldoRealDisponivel = $saldoDisponivel - (float) $valoresEmMediacao;
        
        if ($saldoRealDisponivel < (float)$request->amount) {
            $mensagem = 'Saldo Insuficiente.';
            if ($valoresEmMediacao > 0) {
                $mensagem .= ' Você possui R$ ' . number_format($valoresEmMediacao, 2, ',', '.') . ' em mediação que não podem ser sacados.';
            }
            return response()->json(['status' => 'error', 'message' => $mensagem], 401);
        }

        try {
            $validated = $request->validate([
                'token' =>    ['required', 'string'],
                'secret' =>    ['required', 'string'],
                'amount' =>    ['required'],
                'pixKey' => ['required', 'string'],
                'pixKeyType' =>    ['required', 'string', 'in:cpf,cnpj,email,telefone,phone,aleatoria,random,crypto'],
                'baasPostbackUrl' =>    ['required', 'string']
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erro de validação',
                'errors' => $e->errors()
            ], 422); // Status code 422 para erros de validação
        }

        // Verificar valor mínimo de saque - priorizar configuração específica do usuário
        $valorMinimoSaque = $user->valor_minimo_saque ?? $setting->saque_minimo;
        
        if ($request->amount < $valorMinimoSaque) {
            $saqueminimo = "R$ " . number_format($valorMinimoSaque, '2', ',', '.');
            return response()->json([
                'status' => 'error',
                'message' => "O saque mínimo é de $saqueminimo.",
            ], 401);
        }

        // Verificar se o saque automático está ativo
        if ($setting->saque_automatico) {
            // Nova regra:
            // - Se limite_saque_automatico for NULL => automático para todos os valores
            // - Se houver limite => automático até o limite, acima disso vira manual
            // Considera 'sem limite' quando for null ou <= 0
            $temLimite = !is_null($setting->limite_saque_automatico) && (float)$setting->limite_saque_automatico > 0;
            $dentroDoLimite = !$temLimite || ((float)$request->amount <= (float)$setting->limite_saque_automatico);

            if ($dentroDoLimite) {
                // Processar saque automático
                return $this->processarSaqueAutomatico($request, $default, $setting, $isInterfaceWeb);
            }

            // Valor acima do limite (quando definido): processar como manual
            return $this->processarSaqueManual($request, $default, $isInterfaceWeb);
        } else {
            // Modo manual ativo, processar como manual
            return $this->processarSaqueManual($request, $default, $isInterfaceWeb);
        }
    }

    /**
     * Processa saque automático - executa o pagamento diretamente
     */
    private function processarSaqueAutomatico(Request $request, $default, $setting, $isInterfaceWeb = false)
    {
        // Adicionar flag para indicar que é saque automático
        $request->merge(['saque_automatico' => true]);
        
        return $this->processarSaque($request, $default, true);
    }

    /**
     * Processa saque manual - cria solicitação para aprovação
     */
    private function processarSaqueManual(Request $request, $default, $isInterfaceWeb = false)
    {
        return $this->processarSaque($request, $default, false);
    }

    /**
     * Processa saque
     * 
     * @param Request $request
     * @param string $default
     * @param bool $isAutomatico
     * @return \Illuminate\Http\JsonResponse
     */
    private function processarSaque(Request $request, string $default, bool $isAutomatico = false)
    {
        try {
            // Executar o pagamento baseado no adquirente
            switch ($default) {
                case 'pagarme':
                    // Pagar.me não suporta saques PIX diretamente
                    return response()->json(['status' => 'error', 'message' => 'Adquirente não suportado para saques.'], 500);
                case 'treeal':
                    Log::info('SaqueController - Processando saque Treeal', [
                        'user_id' => $request->user()?->id,
                        'amount' => $request->amount,
                        'is_automatico' => $isAutomatico
                    ]);
                    // processTreealWithdrawal já retorna JsonResponse completo
                    return $this->processTreealWithdrawal($request, $isAutomatico);
                default:
                    return response()->json(['status' => 'error', 'message' => 'Adquirente não suportado.'], 500);
            }
        } catch (\Exception $e) {
            $tipo = $isAutomatico ? 'automático' : 'manual';
            Log::error("Erro no saque {$tipo}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'adquirente' => $default,
                'user_id' => $request->user()?->id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => "Erro ao processar saque {$tipo}. Tente novamente."
            ], 500);
        }
    }

    /**
     * Processa saque PIX usando Treeal/ONZ
     * 
     * Implementação limpa e moderna que serve como referência para futuras integrações
     * 
     * @param Request $request
     * @param bool $isAutomatico Se true, executa o saque imediatamente; se false, cria solicitação manual
     * @return \Illuminate\Http\JsonResponse
     */
    private function processTreealWithdrawal(Request $request, bool $isAutomatico = false)
    {
        try {
            $user = $request->user();
            $treealService = app(TreealService::class);
            $treealConfig = Treeal::first();
            $setting = App::first();

            // Validar se Treeal está configurado e ativo
            if (!$treealConfig || !$treealService->isActive()) {
                Log::error('SaqueController::processTreealWithdrawal - Treeal não configurado ou inativo');
                return response()->json([
                    'status' => 'error',
                    'message' => 'Adquirente Treeal não está configurada ou ativa.'
                ], 500);
            }

            $amount = (float) $request->amount;
            $pixKey = $request->pixKey;
            $pixKeyType = $request->pixKeyType;
            $description = $request->input('description', 'Saque via PIX');

            // Obter taxa da TREEAL
            $taxaTreeal = $treealConfig->taxa_pix_cash_out ?? 0.00;
            
            // Calcular taxas usando o Helper centralizado (garante consistência)
            // Agora considera também a taxa da adquirente (TREEAL)
            // isInterfaceWeb = true para saques via dashboard, false para API
            $isInterfaceWeb = !$request->has('api_key'); // Se não tem api_key, é interface web
            $taxaCalculada = \App\Helpers\TaxaSaqueHelper::calcularTaxaSaque($amount, $setting, $user, $isInterfaceWeb, false, $taxaTreeal);
            $taxaTotal = $taxaCalculada['taxa_cash_out'];
            $taxaAplicacao = $taxaCalculada['taxa_aplicacao'] ?? $taxaTotal;
            $taxaAdquirente = $taxaCalculada['taxa_adquirente'] ?? 0.00;
            $cashOutLiquido = $taxaCalculada['saque_liquido'];
            $valorTotalDescontar = $taxaCalculada['valor_total_descontar'];

            // Verificar saldo disponível considerando a taxa total a ser descontada
            if ($user->saldo < $valorTotalDescontar) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Saldo insuficiente. Valor necessário: R$ ' . number_format($valorTotalDescontar, 2, ',', '.') . ' (incluindo taxa de R$ ' . number_format($taxaTotal, 2, ',', '.') . ')'
                ], 401);
            }

            // Se for saque automático, executar imediatamente
            if ($isAutomatico) {
                // Gerar idempotency key único
                $idempotencyKey = str()->uuid()->toString();

                // Criar saque na API Treeal
                $withdrawalResult = $treealService->createWithdrawalByPixKey(
                    $amount,
                    $pixKey,
                    $description,
                    $idempotencyKey,
                    $pixKeyType
                );

                if (!$withdrawalResult['success']) {
                    Log::error('SaqueController::processTreealWithdrawal - Erro ao criar saque na API', [
                        'error' => $withdrawalResult['message'] ?? 'Erro desconhecido'
                    ]);
                    return response()->json([
                        'status' => 'error',
                        'message' => $withdrawalResult['message'] ?? 'Erro ao processar saque PIX'
                    ], 500);
                }

                $transactionId = $withdrawalResult['transaction_id'] ?? $withdrawalResult['id'] ?? null;
                $status = $withdrawalResult['status'] ?? 'PROCESSING';

                // Criar registro na tabela SolicitacoesCashOut
                $cashOut = SolicitacoesCashOut::create([
                    'user_id' => $user->username,
                    'externalreference' => $transactionId,
                    'amount' => $amount,
                    'beneficiaryname' => $user->name ?? $user->username,
                    'beneficiarydocument' => $pixKey,
                    'pix' => $pixKey,
                    'pixkey' => $pixKeyType,
                    'date' => Carbon::now(),
                    'status' => $this->mapTreealStatusToInternal($status),
                    'type' => 'PIX',
                    'idTransaction' => $transactionId,
                    'taxa_cash_out' => $taxaTotal,
                    'cash_out_liquido' => $cashOutLiquido,
                    'descricao_transacao' => 'AUTOMATICO',
                    'executor_ordem' => 'Treeal',
                ]);

                // Debitar saldo do usuário (thread-safe)
                // Descontar o valor total (valor solicitado + taxa)
                $balanceService = app(\App\Services\BalanceService::class);
                $balanceService->decrementBalance($user, $valorTotalDescontar, 'saldo');
                Helper::calculaSaldoLiquido($user->user_id);

                Log::info('SaqueController::processTreealWithdrawal - Saque automático criado', [
                    'transaction_id' => $transactionId,
                    'amount' => $amount,
                    'cash_out_liquido' => $cashOutLiquido,
                    'cash_out_id' => $cashOut->id
                ]);

                // Padronizar resposta usando ApiResponseStandardizer
                $standardizedResponse = ApiResponseStandardizer::standardizeWithdrawResponse([
                    'data' => [
                        'id' => $transactionId,
                        'idTransaction' => $transactionId,
                        'status' => 'processing',
                        'amount' => $amount,
                        'pixKey' => $pixKey,
                        'pixKeyType' => $pixKeyType,
                        'withdrawStatusId' => 'Processing',
                    ]
                ], $amount);

                return response()->json($standardizedResponse, 200);

            } else {
                // Saque manual - criar solicitação para aprovação
                // Não debitar saldo ainda, apenas criar registro pendente
                $transactionId = str()->uuid()->toString();

                $cashOut = SolicitacoesCashOut::create([
                    'user_id' => $user->username,
                    'externalreference' => $transactionId,
                    'amount' => $amount,
                    'beneficiaryname' => $user->name ?? $user->username,
                    'beneficiarydocument' => $pixKey,
                    'pix' => $pixKey,
                    'pixkey' => $pixKeyType,
                    'date' => Carbon::now(),
                    'status' => 'PENDING',
                    'type' => 'PIX',
                    'idTransaction' => $transactionId,
                    'taxa_cash_out' => $taxaTotal,
                    'cash_out_liquido' => $cashOutLiquido,
                    'descricao_transacao' => 'MANUAL',
                    'executor_ordem' => null, // Manual = sem executor automático
                ]);

                Log::info('SaqueController::processTreealWithdrawal - Saque manual criado', [
                    'transaction_id' => $transactionId,
                    'amount' => $amount,
                    'cash_out_id' => $cashOut->id
                ]);

                // Padronizar resposta usando ApiResponseStandardizer
                $standardizedResponse = ApiResponseStandardizer::standardizeWithdrawResponse([
                    'data' => [
                        'id' => $transactionId,
                        'idTransaction' => $transactionId,
                        'status' => 'pending',
                        'amount' => $amount,
                        'pixKey' => $pixKey,
                        'pixKeyType' => $pixKeyType,
                        'withdrawStatusId' => 'PendingProcessing',
                        'message' => 'Saque criado e aguardando aprovação manual'
                    ]
                ], $amount);

                return response()->json($standardizedResponse, 200);
            }

        } catch (\Exception $e) {
            Log::error('SaqueController::processTreealWithdrawal - Exceção', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Erro ao processar saque PIX: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mapeia status da Treeal para status interno
     * 
     * Status TREEAL (Cash Out - API ONZ):
     * - PROCESSING: Transação em processamento
     * - LIQUIDATED: Transação liquidada com sucesso
     * - CANCELED: Transação cancelada
     * - REFUNDED: Transação estornada
     * - PARTIALLY_REFUNDED: Transação parcialmente estornada
     */
    private function mapTreealStatusToInternal(string $treealStatus): string
    {
        $statusMap = [
            // Status de processamento
            'PROCESSING' => 'PROCESSING',
            'EM_PROCESSAMENTO' => 'PROCESSING',
            
            // Status de sucesso (liquidação)
            'LIQUIDATED' => 'PAID_OUT',
            'COMPLETED' => 'PAID_OUT',
            'CONFIRMED' => 'PAID_OUT',
            
            // Status de falha/cancelamento
            'FAILED' => 'FAILED',
            'CANCELLED' => 'CANCELLED',
            'CANCELED' => 'CANCELLED',
            
            // Status de estorno
            'REFUNDED' => 'REFUNDED',
            'PARTIALLY_REFUNDED' => 'PARTIALLY_REFUNDED',
        ];

        return $statusMap[strtoupper($treealStatus)] ?? 'PENDING';
    }
}
