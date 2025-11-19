<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreManualDepositRequest;
use App\Models\App;
use App\Models\Solicitacoes;
use App\Models\User;
use App\Services\{FinancialService, CacheKeyService};
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Controller para gerenciar transações manuais do admin
 * 
 * @package App\Http\Controllers\Api
 */
class AdminTransactionsController extends Controller
{
    /**
     * Serviço financeiro injetado via container
     */
    private FinancialService $financialService;
    
    /**
     * Constructor com injeção de dependência
     */
    public function __construct(FinancialService $financialService)
    {
        $this->financialService = $financialService;
    }
    
    /**
     * Criar depósito manual
     * 
     * @param StoreManualDepositRequest $request
     * @return JsonResponse
     */
    public function storeDeposit(StoreManualDepositRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::where('user_id', $validated['user_id'])->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não encontrado.',
            ], 404);
        }

        $settings = App::first();
        if (!$settings) {
            return response()->json([
                'success' => false,
                'message' => 'Configurações da aplicação não foram encontradas.',
            ], 500);
        }

        $amount = (float) $validated['amount'];
        $description = $validated['description'] ?? 'MANUAL';

        DB::beginTransaction();

        try {
            $taxaCalculada = \App\Helpers\TaxaFlexivelHelper::calcularTaxaDeposito($amount, $settings, $user);
            $depositoLiquido = $taxaCalculada['deposito_liquido'];
            $taxaCashIn = $taxaCalculada['taxa_cash_in'];

            $idTransaction = str_replace('-', '', (string) Str::uuid());
            $now = Carbon::now();

            $deposit = Solicitacoes::create([
                'user_id' => $user->user_id,
                'externalreference' => env('APP_NAME') . '_' . $idTransaction,
                'amount' => $amount,
                'client_name' => $user->name,
                'client_document' => $user->cpf_cnpj,
                'client_email' => $user->email,
                'date' => $now->format('Y-m-d H:i:s'),
                'status' => 'PAID_OUT',
                'idTransaction' => $idTransaction,
                'deposito_liquido' => $depositoLiquido,
                'qrcode_pix' => '',
                'paymentcode' => '',
                'paymentCodeBase64' => '',
                'adquirente_ref' => env('APP_NAME'),
                'taxa_cash_in' => $taxaCashIn,
                'taxa_pix_cash_in_adquirente' => 0,
                'taxa_pix_cash_in_valor_fixo' => 0,
                'client_telefone' => $user->telefone,
                'executor_ordem' => env('APP_NAME'),
                'descricao_transacao' => $description,
            ]);

            \App\Helpers\Helper::incrementAmount($user, $depositoLiquido, 'saldo');
            \App\Helpers\Helper::calculaSaldoLiquido($user->user_id);

            DB::commit();

            // Limpar caches relacionados (fail-safe)
            $this->clearRelatedCaches();

            return response()->json([
                'success' => true,
                'message' => 'Depósito manual criado com sucesso.',
                'data' => [
                    'deposit' => [
                        'id' => $deposit->id,
                        'transaction_id' => $deposit->idTransaction,
                        'amount' => $deposit->amount,
                        'valor_liquido' => $deposit->deposito_liquido,
                        'taxa' => $deposit->amount - $deposit->deposito_liquido,
                        'status' => $deposit->status,
                        'descricao' => $deposit->descricao_transacao,
                        'created_at' => $deposit->created_at?->toIso8601String(),
                        'user' => [
                            'id' => $user->id,
                            'user_id' => $user->user_id,
                            'name' => $user->name,
                            'username' => $user->username,
                        ],
                    ],
                ],
            ], 201);
        } catch (\Throwable $exception) {
            DB::rollBack();

            Log::error('Erro ao criar depósito manual', [
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Não foi possível criar o depósito manual.',
            ], 500);
        }
    }
    
    /**
     * Limpar caches relacionados após criar depósito
     * Fail-safe: não interrompe a operação se cache falhar
     * 
     * @return void
     */
    private function clearRelatedCaches(): void
    {
        try {
            $this->financialService->invalidateDepositsCache();
        } catch (\Throwable $exception) {
            Log::warning('Falha ao limpar cache financeiro após depósito manual', [
                'error' => $exception->getMessage(),
            ]);
        }
        
        try {
            CacheKeyService::forgetAdminRecentTransactions();
        } catch (\Throwable $exception) {
            Log::warning('Falha ao limpar cache de transações recentes do admin', [
                'error' => $exception->getMessage(),
            ]);
        }
    }
}

