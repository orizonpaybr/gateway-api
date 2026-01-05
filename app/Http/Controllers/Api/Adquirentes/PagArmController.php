<?php

namespace App\Http\Controllers\Api\Adquirentes;

use App\Http\Controllers\Controller;
use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use App\Models\User;
use App\Models\PagArm;
use App\Helpers\Helper;
use App\Traits\SplitTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class PagArmController extends Controller
{
    /**
     * Callback para depósitos (PIX IN)
     */
    public function callbackDeposit(Request $request)
    {
        try {
            $data = $request->all();
            Log::info('=== PAGARM CALLBACK DEPOSIT ===');
            Log::info('PagArmController::callbackDeposit - Dados recebidos:', $data);

            // Validar webhook secret
            $pagarm = PagArm::first();
            if (!$pagarm || !$pagarm->webhook_secret) {
                Log::error('[PAGARM][CALLBACK]: PagArm não configurado ou webhook_secret não definido');
                return response()->json(['status' => 'error', 'message' => 'Webhook não configurado'], 500);
            }

            $webhookSecret = $request->header('X-Webhook-Secret') ?? $request->get('webhook_secret');
            if (!$webhookSecret || $webhookSecret !== $pagarm->webhook_secret) {
                Log::error('[PAGARM][CALLBACK]: Webhook secret inválido');
                return response()->json(['status' => 'error', 'message' => 'Webhook secret inválido'], 401);
            }

            // Verificar se é uma notificação de pagamento
            $event = $data['event'] ?? '';
            if (!in_array($event, ['payment.completed', 'payment.approved', 'pix.received'])) {
                Log::info('[PAGARM][CALLBACK]: Evento não é de pagamento, ignorando. Evento: ' . $event);
                return response()->json(['status' => 'success', 'message' => 'Evento ignorado']);
            }

            // Extrair dados da transação
            $transactionId = $data['transaction_id'] ?? $data['id'] ?? null;
            $externalId = $data['external_id'] ?? null;
            $status = $data['status'] ?? '';

            if (!$transactionId) {
                Log::error('[PAGARM][CALLBACK]: Transaction ID não encontrado');
                return response()->json(['status' => 'error', 'message' => 'Transaction ID não encontrado'], 400);
            }

            Log::info('[PAGARM][CALLBACK]: Processando callback de depósito', [
                'transaction_id' => $transactionId,
                'external_id' => $externalId,
                'status' => $status
            ]);

            // Buscar solicitação pelo transaction_id ou external_id
            $solicitacao = Solicitacoes::where('idTransaction', $transactionId)
                ->orWhere('external_id', $externalId)
                ->first();

            if (!$solicitacao) {
                Log::error('[PAGARM][CALLBACK]: Solicitação não encontrada', [
                    'transaction_id' => $transactionId,
                    'external_id' => $externalId
                ]);
                return response()->json(['status' => 'error', 'message' => 'Solicitação não encontrada'], 404);
            }

            // Verificar se já foi processada
            if (in_array($solicitacao->status, ['PAID_OUT', 'COMPLETED', 'APPROVED'])) {
                Log::info('[PAGARM][CALLBACK]: Solicitação já processada', [
                    'solicitacao_id' => $solicitacao->id,
                    'status_atual' => $solicitacao->status
                ]);
                return response()->json(['status' => 'success', 'message' => 'Já processada']);
            }

            // Determinar novo status
            $newStatus = 'PENDING';
            if (in_array($status, ['completed', 'approved', 'paid', 'success'])) {
                $newStatus = 'PAID_OUT';
            } elseif (in_array($status, ['failed', 'rejected', 'cancelled', 'error'])) {
                $newStatus = 'CANCELLED';
            }

            Log::info('[PAGARM][CALLBACK]: Atualizando status da solicitação', [
                'solicitacao_id' => $solicitacao->id,
                'status_anterior' => $solicitacao->status,
                'status_novo' => $newStatus
            ]);

            // Atualizar status da solicitação
            $solicitacao->update([
                'status' => $newStatus,
                'updated_at' => Carbon::now()
            ]);

            // Se aprovado, creditar saldo e processar splits
            if ($newStatus === 'PAID_OUT') {
                $user = User::where('username', $solicitacao->user_id)->first();
                if ($user) {
                    // Creditar saldo
                    Helper::incrementAmount($user, $solicitacao->deposito_liquido, 'saldo');
                    Helper::calculaSaldoLiquido($user->user_id);

                    Log::info('[PAGARM][CALLBACK]: Saldo creditado', [
                        'user_id' => $user->id,
                        'valor_creditado' => $solicitacao->deposito_liquido
                    ]);

                    // Processar splits automáticos
                    SplitTrait::processSplits($solicitacao, $user);

                    Log::info('[PAGARM][CALLBACK]: Splits processados');
                }
            }

            Log::info('[PAGARM][CALLBACK]: Callback processado com sucesso');
            return response()->json(['status' => 'success', 'message' => 'Callback processado']);

        } catch (\Exception $e) {
            Log::error('[PAGARM][CALLBACK]: Erro ao processar callback', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json(['status' => 'error', 'message' => 'Erro interno'], 500);
        }
    }

    /**
     * Callback para saques (PIX OUT)
     */
    public function callbackWithdraw(Request $request)
    {
        try {
            $data = $request->all();
            Log::info('=== PAGARM CALLBACK WITHDRAW ===');
            Log::info('PagArmController::callbackWithdraw - Dados recebidos:', $data);

            // Validar webhook secret
            $pagarm = PagArm::first();
            if (!$pagarm || !$pagarm->webhook_secret) {
                Log::error('[PAGARM][WITHDRAW_CALLBACK]: PagArm não configurado');
                return response()->json(['status' => 'error', 'message' => 'Webhook não configurado'], 500);
            }

            $webhookSecret = $request->header('X-Webhook-Secret') ?? $request->get('webhook_secret');
            if (!$webhookSecret || $webhookSecret !== $pagarm->webhook_secret) {
                Log::error('[PAGARM][WITHDRAW_CALLBACK]: Webhook secret inválido');
                return response()->json(['status' => 'error', 'message' => 'Webhook secret inválido'], 401);
            }

            // Verificar se é uma notificação de saque
            $event = $data['event'] ?? '';
            if (!in_array($event, ['withdraw.completed', 'withdraw.failed', 'pix.sent'])) {
                Log::info('[PAGARM][WITHDRAW_CALLBACK]: Evento não é de saque, ignorando. Evento: ' . $event);
                return response()->json(['status' => 'success', 'message' => 'Evento ignorado']);
            }

            // Extrair dados da transação
            $transactionId = $data['transaction_id'] ?? $data['id'] ?? null;
            $externalId = $data['external_id'] ?? null;
            $status = $data['status'] ?? '';

            if (!$transactionId) {
                Log::error('[PAGARM][WITHDRAW_CALLBACK]: Transaction ID não encontrado');
                return response()->json(['status' => 'error', 'message' => 'Transaction ID não encontrado'], 400);
            }

            Log::info('[PAGARM][WITHDRAW_CALLBACK]: Processando callback de saque', [
                'transaction_id' => $transactionId,
                'external_id' => $externalId,
                'status' => $status
            ]);

            // Buscar solicitação de saque
            $solicitacaoCashOut = SolicitacoesCashOut::where('idTransaction', $transactionId)
                ->orWhere('external_id', $externalId)
                ->first();

            if (!$solicitacaoCashOut) {
                Log::error('[PAGARM][WITHDRAW_CALLBACK]: Solicitação de saque não encontrada', [
                    'transaction_id' => $transactionId,
                    'external_id' => $externalId
                ]);
                return response()->json(['status' => 'error', 'message' => 'Solicitação não encontrada'], 404);
            }

            // Verificar se já foi processada
            if (in_array($solicitacaoCashOut->status, ['PAID_OUT', 'COMPLETED', 'APPROVED'])) {
                Log::info('[PAGARM][WITHDRAW_CALLBACK]: Solicitação já processada', [
                    'solicitacao_id' => $solicitacaoCashOut->id,
                    'status_atual' => $solicitacaoCashOut->status
                ]);
                return response()->json(['status' => 'success', 'message' => 'Já processada']);
            }

            // Determinar novo status
            $newStatus = 'PENDING';
            if (in_array($status, ['completed', 'approved', 'sent', 'success'])) {
                $newStatus = 'PAID_OUT';
            } elseif (in_array($status, ['failed', 'rejected', 'cancelled', 'error'])) {
                $newStatus = 'CANCELLED';
                
                // Se falhou, devolver o valor para o saldo do usuário
                $user = User::where('username', $solicitacaoCashOut->user_id)->first();
                if ($user) {
                    $valorDevolver = $solicitacaoCashOut->amount + $solicitacaoCashOut->taxa_cash_out;
                    Helper::incrementAmount($user, $valorDevolver, 'saldo');
                    Helper::calculaSaldoLiquido($user->user_id);

                    Log::info('[PAGARM][WITHDRAW_CALLBACK]: Valor devolvido ao saldo', [
                        'user_id' => $user->id,
                        'valor_devolvido' => $valorDevolver
                    ]);
                }
            }

            Log::info('[PAGARM][WITHDRAW_CALLBACK]: Atualizando status da solicitação', [
                'solicitacao_id' => $solicitacaoCashOut->id,
                'status_anterior' => $solicitacaoCashOut->status,
                'status_novo' => $newStatus
            ]);

            // Atualizar status da solicitação
            $solicitacaoCashOut->update([
                'status' => $newStatus,
                'updated_at' => Carbon::now()
            ]);

            Log::info('[PAGARM][WITHDRAW_CALLBACK]: Callback processado com sucesso');
            return response()->json(['status' => 'success', 'message' => 'Callback processado']);

        } catch (\Exception $e) {
            Log::error('[PAGARM][WITHDRAW_CALLBACK]: Erro ao processar callback', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json(['status' => 'error', 'message' => 'Erro interno'], 500);
        }
    }
}


