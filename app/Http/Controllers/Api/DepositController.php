<?php

namespace App\Http\Controllers\Api;

use App\DTO\PagarMeDTO\CardDepositDTO;
use App\Helpers\ApiResponseStandardizer;
use App\Helpers\Helper;
use App\Helpers\TaxaFlexivelHelper;
use App\Http\Controllers\Controller;
use App\Jobs\ReconcileFluxPaymentsDepositJob;
use App\Jobs\ReconcileFyhubDepositJob;
use App\Jobs\ReconcileTreealDepositJob;
use App\Services\FluxPayments\FluxPaymentsPixAcquirerService;
use App\Models\App;
use App\Models\Pagarme;
use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use App\Services\PagarMeService;
use App\Services\PixAcquirer\PixAcquirerManager;
use App\Traits\PagarMeTrait;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * @OA\Info(
 *     title="API Rest PIX",
 *     version="1.0.0",
 *     description="Documentação"
 * )
 */
class DepositController extends Controller
{
    public function makeDeposit(Request $request)
    {
        // Verificar se o usuário está autenticado
        $user = $request->user();
        Log::info('DepositController - Verificação de usuário', [
            'user_from_request' => $user ? 'Presente' : 'Ausente',
            'user_id' => $user ? $user->id : 'N/A',
            'request_data' => $request->all(),
        ]);

        if (! $user) {
            return response()->json(['status' => 'error', 'message' => 'Usuário não autenticado'], 401);
        }

        $setting = App::first();
        if (! $setting) {
            return response()->json(['status' => 'error', 'message' => 'Configurações do aplicativo não encontradas.'], 500);
        }

        $default = Helper::adquirenteDefault($user->username ?? $user->user_id);
        Log::info('DepositController - Adquirente default', ['adquirente' => $default]);
        if (! $default) {
            Log::info('DepositController - Nenhum adquirente configurado', []);

            return response()->json(['status' => 'error', 'message' => 'Nenhum adquirente configurado.'], 500);
        }

        try {
            $validated = $request->validate([
                'token' => ['nullable', 'string'],
                'secret' => ['nullable', 'string'],
                'amount' => ['required'],
                'debtor_name' => ['required', 'string'],
                'email' => ['required', 'string', 'email'],
                'debtor_document_number' => ['nullable', 'string'],
                'phone' => ['nullable', 'string'],
                'method_pay' => ['nullable', 'string'],
                'postback' => ['nullable', 'string'],
                'description' => ['nullable', 'string', 'max:140'],
                'split_email' => ['nullable', 'string', 'email'],
                'split_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erro de validação',
                'errors' => $e->errors(),
            ], 422); // Status code 422 para erros de validação
        }

        // IMPORTANTE: Recarregar usuário do banco para garantir dados atualizados (evita cache)
        // Isso é crítico para garantir que taxas personalizadas atualizadas sejam aplicadas
        if ($user && isset($user->user_id)) {
            $user = \App\Models\User::where('user_id', $user->user_id)->first();
            Log::info('DepositController - Usuário recarregado do banco', [
                'user_id' => $user->user_id ?? 'N/A',
                'taxas_personalizadas_ativas' => $user->taxas_personalizadas_ativas ?? false,
                'taxa_fixa_deposito' => $user->taxa_fixa_deposito ?? 'N/A',
            ]);
        }

        Log::info('DepositController - Executando switch para adquirente', ['adquirente' => $default]);
        switch ($default) {
            case 'pagarme':
                Log::info('DepositController - Executando PagarMeTrait', []);
                $response = PagarMeTrait::requestDepositPagarme($request);
                break;
            default:
                $response = $this->processPixDepositUsingAcquirer($request, $user, $setting, (string) $default);
                break;
        }

        // Verificar se a resposta foi definida
        if (! isset($response)) {
            return response()->json(['status' => 'error', 'message' => 'Erro ao processar depósito.'], 500);
        }

        // Se passar pela validação, processar o depósito
        if ($response['status'] === 200) {
            // Padronizar a resposta usando o sistema de padronização
            $standardizedResponse = ApiResponseStandardizer::standardizeDepositResponse(
                $response['data'],
                $request->amount
            );

            return response()->json($standardizedResponse, 200);
        }

        return response()->json($response['data'], $response['status']);
    }

    public function statusDeposito(Request $request)
    {
        $transactionId = trim((string) $request->input('idTransaction', ''));
        if ($transactionId === '') {
            return response()->json(['status' => 'NOT_FOUND']);
        }

        $deposit = $this->findSolicitacaoByTransactionId($transactionId);
        if ($deposit) {
            return response()->json($this->buildStatusResponse($deposit));
        }

        $cashOut = $this->findCashOutByTransactionId($transactionId);
        if ($cashOut) {
            return response()->json($this->buildStatusResponse($cashOut));
        }

        return response()->json(['status' => 'NOT_FOUND']);
    }

    private function findSolicitacaoByTransactionId(string $transactionId): ?Solicitacoes
    {
        return Solicitacoes::where('idTransaction', $transactionId)->first()
            ?? Solicitacoes::where('externalreference', $transactionId)->first();
    }

    private function findCashOutByTransactionId(string $transactionId): ?SolicitacoesCashOut
    {
        return SolicitacoesCashOut::where('idTransaction', $transactionId)->first()
            ?? SolicitacoesCashOut::where('externalreference', $transactionId)->first();
    }

    private function buildStatusResponse($transaction): array
    {
        $response = ['status' => $transaction->status];

        if ($transaction->webhook_status !== null) {
            $response['webhook'] = [
                'delivery_status' => $transaction->webhook_status,
                'sent_at' => $transaction->webhook_sent_at,
                'http_status' => $transaction->webhook_http_status,
                'attempts' => $transaction->webhook_attempts ?? 0,
            ];

            if ($transaction->webhook_status !== 'delivered') {
                $response['webhook']['error'] = $transaction->webhook_error;
            }

            if (! empty($transaction->webhook_request_body)) {
                $decoded = json_decode($transaction->webhook_request_body, true);
                $response['webhook']['request_body'] = $decoded !== null ? $decoded : $transaction->webhook_request_body;
            }
        }

        return $response;
    }

    /**
     * Processa depósito via cartão de crédito usando Pagar.me
     *
     * @OA\Post(
     *     path="/api/deposit/card",
     *     summary="Criar depósito via cartão de crédito",
     *     tags={"Depósitos"},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"amount", "debtor_name", "email", "debtor_document"},
     *
     *             @OA\Property(property="amount", type="number", example=100.00),
     *             @OA\Property(property="debtor_name", type="string", example="João Silva"),
     *             @OA\Property(property="email", type="string", example="joao@email.com"),
     *             @OA\Property(property="debtor_document", type="string", example="12345678900"),
     *             @OA\Property(property="phone", type="string", example="11999999999"),
     *             @OA\Property(property="card_token", type="string", description="Token do cartão (Tokenizecard JS)"),
     *             @OA\Property(property="card_id", type="string", description="ID de cartão salvo"),
     *             @OA\Property(property="installments", type="integer", example=1),
     *             @OA\Property(property="use_3ds", type="boolean", example=true),
     *             @OA\Property(property="callbackUrl", type="string", example="https://seu-site.com/callback")
     *         )
     *     ),
     *
     *     @OA\Response(response=200, description="Depósito criado com sucesso"),
     *     @OA\Response(response=400, description="Dados inválidos"),
     *     @OA\Response(response=401, description="Não autorizado"),
     *     @OA\Response(response=500, description="Erro interno")
     * )
     */
    public function makeCardDeposit(Request $request)
    {
        try {
            // Verificar autenticação
            $user = $request->user_auth;
            if (! $user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuário não autenticado',
                ], 401);
            }

            Log::info('DepositController::makeCardDeposit - Iniciando depósito com cartão', [
                'user_id' => $user->id,
                'amount' => $request->amount,
            ]);

            // Verificar se Pagar.me está configurado para cartão
            $pagarmeConfig = Pagarme::first();
            if (! $pagarmeConfig || ! $pagarmeConfig->isCardEnabled()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Pagamentos com cartão não estão habilitados',
                ], 400);
            }

            // Validar request
            $validated = $request->validate([
                'amount' => ['required', 'numeric', 'min:1'],
                'debtor_name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email'],
                'debtor_document' => ['required', 'string'],
                'phone' => ['nullable', 'string'],
                'card_token' => ['required_without_all:card_id,card', 'string'],
                'card_id' => ['required_without_all:card_token,card', 'string'],
                'card' => ['required_without_all:card_token,card_id', 'array'],
                'card.number' => ['required_with:card', 'string'],
                'card.holder_name' => ['required_with:card', 'string'],
                'card.exp_month' => ['required_with:card', 'integer', 'between:1,12'],
                'card.exp_year' => ['required_with:card', 'integer', 'min:'.date('Y')],
                'card.cvv' => ['required_with:card', 'string', 'size:3'],
                'installments' => ['nullable', 'integer', 'between:1,12'],
                'use_3ds' => ['nullable', 'boolean'],
                'callbackUrl' => ['nullable', 'url'],
                'save_card' => ['nullable', 'boolean'],
            ]);

            $setting = App::first();

            // Criar DTO
            $dto = CardDepositDTO::fromRequest($request);

            // Validar DTO
            if (! $dto->isValid()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Dados inválidos',
                    'errors' => $dto->getValidationErrors(),
                ], 400);
            }

            // Processar depósito
            $pagarmeService = new PagarMeService;

            // Usar 3DS conforme configuração
            $use3ds = $request->input('use_3ds', $pagarmeConfig->is3dsEnabled());

            $serviceData = $dto->toServiceArray();
            $serviceData['use_3ds'] = $use3ds;
            $serviceData['statement_descriptor'] = $setting->gateway_name ?? 'GATEWAY';

            // Chamar API Pagar.me
            $response = $pagarmeService->createCardOrder($serviceData);

            if (! $response || isset($response['error'])) {
                Log::error('DepositController::makeCardDeposit - Erro na API Pagar.me', [
                    'response' => $response,
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => $response['message'] ?? 'Erro ao processar pagamento com cartão',
                ], 400);
            }

            // Calcular taxas
            $fees = $pagarmeService->calculateCardFees($request->amount);

            // Criar registro de solicitação
            $transactionId = $response['id'];
            $chargeId = $response['charges'][0]['id'] ?? null;
            $chargeStatus = $response['charges'][0]['status'] ?? 'pending';

            $cashin = Solicitacoes::create([
                'user_id' => $user->username,
                'externalreference' => $transactionId,
                'amount' => $request->amount,
                'client_name' => $dto->customerName,
                'client_document' => $dto->customerDocument,
                'client_email' => $dto->customerEmail,
                'client_telefone' => $dto->customerPhone,
                'date' => Carbon::now(),
                'status' => $this->mapPagarmeStatus($chargeStatus),
                'idTransaction' => $transactionId,
                'deposito_liquido' => $fees['net_amount'],
                'taxa_cash_in' => $fees['total_fee'],
                'taxa_pix_cash_in_adquirente' => $pagarmeConfig->card_tx_percent,
                'taxa_pix_cash_in_valor_fixo' => $pagarmeConfig->card_tx_fixed,
                'adquirente_ref' => 'PagarMe_Card',
                'executor_ordem' => 'PagarMe_Card',
                'descricao_transacao' => 'CARTAO',
                'callback' => $dto->callbackUrl,
                'method' => 'card',
                'installments' => $dto->installments,
                'charge_id' => $chargeId,
            ]);

            // Se pagamento foi aprovado imediatamente, creditar saldo (thread-safe)
            if (in_array($chargeStatus, ['paid', 'captured'])) {
                // Buscar transação criada para processar de forma atômica
                $solicitacao = Solicitacoes::where('idTransaction', $chargeId)->first();

                if ($solicitacao) {
                    try {
                        $paymentService = app(\App\Services\PaymentProcessingService::class);
                        $paymentService->processPaymentReceived($solicitacao);
                    } catch (\Exception $e) {
                        Log::error('Erro ao processar pagamento aprovado imediatamente', [
                            'charge_id' => $chargeId,
                            'error' => $e->getMessage(),
                        ]);
                        // Se já foi processado, continuar normalmente
                        $solicitacao->refresh();
                        if ($solicitacao->status !== 'PAID_OUT') {
                            throw $e;
                        }
                    }
                } else {
                    // Fallback: usar BalanceService diretamente se transação não encontrada
                    $balanceService = app(\App\Services\BalanceService::class);
                    $balanceService->incrementBalance($user, $fees['net_amount'], 'saldo');
                    Helper::calculaSaldoLiquido($user->user_id);
                }
            }

            // Salvar cartão se solicitado
            if ($request->input('save_card') && isset($response['charges'][0]['last_transaction']['card'])) {
                $cardData = $response['charges'][0]['last_transaction']['card'];
                $pagarmeService->saveUserCard($user->id, $cardData);
            }

            Log::info('DepositController::makeCardDeposit - Depósito criado com sucesso', [
                'transaction_id' => $transactionId,
                'charge_status' => $chargeStatus,
                'amount' => $request->amount,
                'net_amount' => $fees['net_amount'],
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Pagamento processado com sucesso',
                'data' => [
                    'idTransaction' => $transactionId,
                    'charge_id' => $chargeId,
                    'status' => $chargeStatus,
                    'amount' => $request->amount,
                    'net_amount' => $fees['net_amount'],
                    'fee' => $fees['total_fee'],
                    'installments' => $dto->installments,
                    'days_availability' => $fees['days_availability'],
                    // Dados para 3DS se necessário
                    'authentication_url' => $response['charges'][0]['last_transaction']['threed_secure_url'] ?? null,
                ],
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erro de validação',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('DepositController::makeCardDeposit - Exceção:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Erro interno ao processar pagamento',
            ], 500);
        }
    }

    /**
     * Lista cartões salvos do usuário
     */
    public function listSavedCards(Request $request)
    {
        try {
            $user = $request->user();
            if (! $user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuário não autenticado',
                ], 401);
            }

            $cards = \App\Models\UserCard::where('user_id', $user->id)
                ->active()
                ->notExpired()
                ->orderBy('is_default', 'desc')
                ->orderBy('last_used_at', 'desc')
                ->get()
                ->map(fn ($card) => $card->toDisplayArray());

            return response()->json([
                'status' => 'success',
                'data' => $cards,
            ]);

        } catch (\Exception $e) {
            Log::error('DepositController::listSavedCards - Exceção:', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Erro ao listar cartões',
            ], 500);
        }
    }

    /**
     * Remove um cartão salvo
     */
    public function deleteSavedCard(Request $request, $cardId)
    {
        try {
            $user = $request->user();
            if (! $user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuário não autenticado',
                ], 401);
            }

            $card = \App\Models\UserCard::where('user_id', $user->id)
                ->where('id', $cardId)
                ->first();

            if (! $card) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cartão não encontrado',
                ], 404);
            }

            // Remover da Pagar.me se tiver customer_id
            if ($card->customer_id && $card->card_id) {
                $pagarmeService = new PagarMeService;
                $pagarmeService->deleteCustomerCard($card->customer_id, $card->card_id);
            }

            $card->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Cartão removido com sucesso',
            ]);

        } catch (\Exception $e) {
            Log::error('DepositController::deleteSavedCard - Exceção:', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Erro ao remover cartão',
            ], 500);
        }
    }

    /**
     * Define um cartão como padrão
     */
    public function setDefaultCard(Request $request, $cardId)
    {
        try {
            $user = $request->user();
            if (! $user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuário não autenticado',
                ], 401);
            }

            $card = \App\Models\UserCard::where('user_id', $user->id)
                ->where('id', $cardId)
                ->first();

            if (! $card) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cartão não encontrado',
                ], 404);
            }

            $card->setAsDefault();

            return response()->json([
                'status' => 'success',
                'message' => 'Cartão definido como padrão',
            ]);

        } catch (\Exception $e) {
            Log::error('DepositController::setDefaultCard - Exceção:', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Erro ao definir cartão padrão',
            ], 500);
        }
    }

    /**
     * Mapeia status da Pagar.me para status interno
     */
    private function mapPagarmeStatus(string $pagarmeStatus): string
    {
        $statusMap = [
            'pending' => 'WAITING_FOR_APPROVAL',
            'processing' => 'PROCESSING',
            'authorized' => 'AUTHORIZED',
            'paid' => 'PAID_OUT',
            'captured' => 'PAID_OUT',
            'refunded' => 'REFUNDED',
            'voided' => 'CANCELLED',
            'canceled' => 'CANCELLED',
            'failed' => 'FAILED',
            'chargedback' => 'CHARGEBACK',
        ];

        return $statusMap[strtolower($pagarmeStatus)] ?? 'PENDING';
    }

    private function processPixDepositUsingAcquirer(Request $request, $user, $setting, string $acquirerRef): array
    {
        $acquirerManager = app(PixAcquirerManager::class);
        $acquirerService = $acquirerManager->resolve($acquirerRef);

        if (! $acquirerService->isActive()) {
            return [
                'status' => 503,
                'data' => ['status' => 'error', 'message' => 'PIX temporariamente indisponível.'],
            ];
        }

        $correlationId = preg_replace('/[^a-zA-Z0-9]/', '', Str::uuid()->toString());
        $debtor = $this->resolvePixDebtorFromRequest($request, $user);

        if ((float) $request->amount >= 0.10 && strlen($debtor['document']) < 11) {
            return [
                'status' => 422,
                'data' => [
                    'status' => 'error',
                    'message' => 'CPF/CNPJ do devedor é obrigatório para depósitos PIX a partir de R$ 0,10. Informe debtor_document_number ou complete o cadastro.',
                ],
            ];
        }

        $customer = [
            'name' => $debtor['name'],
            'email' => $debtor['email'],
            'document' => $debtor['document'],
            'phone' => $debtor['phone'],
        ];

        $chargeResult = $acquirerService->createCharge(
            (float) $request->amount,
            $customer,
            $correlationId,
            $this->resolvePayerMessageForPixCharge($request),
            null
        );

        if (! ($chargeResult['success'] ?? false)) {
            return [
                'status' => 503,
                'data' => [
                    'status' => 'error',
                    'message' => $chargeResult['message'] ?? 'Não foi possível gerar cobrança PIX.',
                ],
            ];
        }

        try {
            $fees = TaxaFlexivelHelper::calcularTaxaDeposito((float) $request->amount, $setting, $user, $acquirerRef);
        } catch (\Throwable $e) {
            Log::error('DepositController::processPixDepositUsingAcquirer — taxa', [
                'message' => $e->getMessage(),
            ]);

            return [
                'status' => 500,
                'data' => ['status' => 'error', 'message' => 'Erro ao calcular taxas do depósito.'],
            ];
        }

        $brCode = $chargeResult['brCode'] ?? $chargeResult['qr_code'] ?? null;
        $idTxn = $chargeResult['correlationID'] ?? $correlationId;
        $rawStatus = strtolower((string) ($chargeResult['status'] ?? ''));
        $statusCharge = $rawStatus === 'created' || $rawStatus === 'pending' || $rawStatus === 'new'
            ? 'WAITING_FOR_APPROVAL'
            : ($chargeResult['status'] ?? 'WAITING_FOR_APPROVAL');

        $phoneForStorage = $debtor['phone'] !== '' ? $debtor['phone'] : 'N/A';

        $cashin = [
            'user_id' => $user->username,
            'externalreference' => $idTxn,
            'amount' => $request->amount,
            'client_name' => $debtor['name'],
            'client_document' => $debtor['document'],
            'client_email' => $debtor['email'],
            'client_telefone' => $phoneForStorage,
            'date' => Carbon::now(),
            'status' => $statusCharge,
            'idTransaction' => $idTxn,
            'deposito_liquido' => $fees['deposito_liquido'],
            'qrcode_pix' => $brCode,
            'paymentcode' => $brCode,
            'paymentCodeBase64' => $brCode,
            'adquirente_ref' => $acquirerRef,
            'taxa_cash_in' => $fees['taxa_cash_in'],
            'taxa_pix_cash_in_adquirente' => $fees['taxa_adquirente'],
            'taxa_pix_cash_in_valor_fixo' => $fees['taxa_aplicacao'],
            'executor_ordem' => $acquirerService->getReference(),
            'descricao_transacao' => $fees['descricao'] ?? 'PIX',
            'callback' => $request->postback,
            'split_email' => $request->input('split_email'),
            'split_percentage' => $request->input('split_percentage'),
        ];

        $deposit = Solicitacoes::create($cashin);

        Log::info('DepositController - Cob PIX criada', [
            'deposit_id' => $deposit->id,
            'txid' => $idTxn,
            'user_id' => $user->username,
            'executor_ordem' => $acquirerService->getReference(),
            'has_postback' => ! empty($request->postback) && $request->postback !== 'web',
        ]);

        if ($acquirerService->getReference() === 'fyhub') {
            ReconcileFyhubDepositJob::dispatch($deposit->id)->delay(now()->addSeconds(45));
            ReconcileFyhubDepositJob::dispatch($deposit->id)->delay(now()->addSeconds(120));
        }

        if ($acquirerService->getReference() === 'treeal') {
            ReconcileTreealDepositJob::dispatch($deposit->id)->delay(now()->addSeconds(45));
            ReconcileTreealDepositJob::dispatch($deposit->id)->delay(now()->addSeconds(120));
        }

        if ($acquirerService instanceof FluxPaymentsPixAcquirerService) {
            ReconcileFluxPaymentsDepositJob::dispatch($deposit->id)->delay(now()->addSeconds(45));
            ReconcileFluxPaymentsDepositJob::dispatch($deposit->id)->delay(now()->addSeconds(120));
        }

        return [
            'status' => 200,
            'data' => [
                'status' => 'success',
                'message' => 'QR Code gerado com sucesso',
                'idTransaction' => $idTxn,
                'qr_code' => $brCode,
                'qr_code_image_url' => $chargeResult['qrCodeImage'] ?? null,
            ],
        ];
    }

    /**
     * Dados do devedor para cobrança PIX (API + dashboard).
     * TREEAL exige objeto devedor (nome + CPF/CNPJ) em cobranças a partir de R$ 0,10.
     * Se o cliente não enviar debtor_document_number, usa cpf_cnpj do usuário autenticado.
     *
     * @return array{name: string, document: string, email: string, phone: string}
     */
    private function resolvePixDebtorFromRequest(Request $request, $user): array
    {
        $document = preg_replace('/\D/', '', (string) ($request->debtor_document_number ?? ''));
        if ($document === '') {
            $document = preg_replace('/\D/', '', (string) ($user->cpf_cnpj ?? ''));
        }

        $name = trim((string) ($request->debtor_name ?? ''));
        if ($name === '') {
            $name = trim((string) ($user->name ?? $user->username ?? ''));
        }

        $email = trim((string) ($request->email ?? ''));
        if ($email === '') {
            $email = trim((string) ($user->email ?? ''));
        }

        $phone = preg_replace('/\D/', '', (string) ($request->phone ?? ''));
        if ($phone === '') {
            $phone = preg_replace('/\D/', '', (string) ($user->telefone ?? ''));
        }
        // Garante DDD+número (10-11 dígitos): telefone incompleto no cadastro ou ausente no
        // formulário faria a adquirente recusar o PIX ("Validation failed"). Fallback válido.
        $phone = Helper::normalizePhoneForAcquirer($phone);

        return [
            'name' => $name,
            'document' => $document,
            'email' => $email,
            'phone' => $phone,
        ];
    }

    /**
     * Texto exibido ao pagador no PIX (solicitacaoPagador na adquirente).
     * postback/callback são apenas para webhook Coratri → integrador e não devem ir aqui.
     */
    private function resolvePayerMessageForPixCharge(Request $request): ?string
    {
        $description = trim((string) $request->input('description', ''));
        if ($description === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $description)) {
            Log::warning('DepositController - description ignorada por parecer URL', [
                'user_id' => $request->user()?->id,
            ]);

            return null;
        }

        return mb_substr($description, 0, 140);
    }
}
