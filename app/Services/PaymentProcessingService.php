<?php

namespace App\Services;

use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Traits\SplitTrait;

/**
 * Service para processamento atômico de pagamentos
 * 
 * Garante que todas as operações relacionadas a um pagamento sejam executadas
 * de forma atômica (tudo ou nada)
 */
class PaymentProcessingService
{
    public function __construct(
        private BalanceService $balanceService,
        private PaymentEventService $eventService
    ) {}
    
    /**
     * Processa pagamento recebido de forma atômica
     * 
     * @param Solicitacoes $cashin Transação de depósito
     * @return void
     * @throws \Exception Se processamento falhar
     */
    public function processPaymentReceived(Solicitacoes $cashin): void
    {
        DB::transaction(function () use ($cashin) {
            // Lock no registro da transação
            $cashin = Solicitacoes::where('id', $cashin->id)
                ->lockForUpdate()
                ->first();
            
            if (!$cashin) {
                throw new \Exception("Transação não encontrada: {$cashin->id}");
            }
            
            // Verificar idempotência
            if ($cashin->status === 'PAID_OUT' || $cashin->status === 'COMPLETED') {
                Log::info("Pagamento já processado anteriormente", [
                    'transaction_id' => $cashin->idTransaction,
                    'status' => $cashin->status,
                ]);
                return; // Idempotência - já foi processado
            }
            
            // Lock no usuário
            $user = User::where('user_id', $cashin->user_id)
                ->lockForUpdate()
                ->first();
            
            if (!$user) {
                throw new \Exception("Usuário não encontrado: {$cashin->user_id}");
            }
            
            // 1. Atualizar status da transação
            $cashin->update(['status' => 'PAID_OUT']);
            
            // 2. Creditar saldo (thread-safe)
            $balanceBefore = $user->saldo;
            $this->balanceService->incrementBalance(
                $user,
                $cashin->deposito_liquido,
                'saldo'
            );
            $balanceAfter = $user->fresh()->saldo;
            
            // 3. Calcular saldo líquido (dentro da transação)
            \App\Helpers\Helper::calculaSaldoLiquido($user->user_id);
            
            // 3. Registrar evento (auditoria)
            $this->eventService->recordPaymentReceived(
                $cashin,
                $user,
                $balanceBefore,
                $balanceAfter
            );
            
            // 4. Processar splits (dentro da transação)
            if ($cashin->split_email && $cashin->split_percentage) {
                $this->processSplits($cashin, $user);
            }
            
            // 5. Processar comissões de gerente (dentro da transação)
            if ($user->gerente_id) {
                $this->processCommissions($cashin, $user);
            }
            
            // 6. Processar comissões de afiliados (dentro da transação)
            if ($user->affiliate_id) {
                try {
                    $affiliateService = app(\App\Services\AffiliateCommissionService::class);
                    $affiliateService->processCashInCommission($cashin, $user);
                } catch (\Exception $e) {
                    Log::error("Erro ao processar comissão de afiliado", [
                        'transaction_id' => $cashin->idTransaction,
                        'error' => $e->getMessage(),
                    ]);
                    // Não re-throw - comissões são opcionais, não devem quebrar o pagamento
                }
            }
            
            Log::info("Pagamento processado com sucesso", [
                'transaction_id' => $cashin->idTransaction,
                'user_id' => $user->user_id,
                'amount' => $cashin->amount,
                'amount_credited' => $cashin->deposito_liquido,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
            ]);
            
            // Tudo ou nada - se qualquer coisa falhar, rollback completo
        });

        // Invalidar caches FORA da transaction (após commit bem-sucedido)
        $this->invalidateCachesAfterPayment($cashin->user_id);
    }

    /**
     * Invalida todos os caches relacionados ao usuário (saldo, dashboard, transações, etc.).
     * Chamado após depósito creditado, saque processado, criação de saque (débito) ou aprovação/rejeição.
     * Público para ser usado em SaqueController, PixKeyController e WithdrawalController.
     */
    public function invalidateCachesAfterPayment(string $userId): void
    {
        try {
            $user = User::where('user_id', $userId)->orWhere('username', $userId)->first();
            if (!$user) {
                Log::debug("Usuário não encontrado para invalidar cache", ['user_id' => $userId]);
                return;
            }

            $username = $user->username;
            $userNumericId = $user->id;

            // Saldo (user_balance_ inclui totalInflows / totalOutflows)
            Cache::forget("user_balance_{$username}");

            // Dashboard stats — "Entradas do Mês", tickets, conversão, etc.
            $this->forgetCacheByPattern("dash:stats:{$username}:");
            // Sem Redis, o fallback não invalida a chave real (dash:stats:user:Ym:Ym) — esquecer explicitamente
            if (config('cache.default') !== 'redis') {
                $startOfMonth = \Carbon\Carbon::now()->startOfMonth();
                $endOfMonth = \Carbon\Carbon::now()->endOfMonth();
                Cache::forget(sprintf('dash:stats:%s:%s:%s', $username, $startOfMonth->format('Ym'), $endOfMonth->format('Ym')));
            }

            // Dashboard summary e gráfico interativo
            $this->forgetCacheByPattern("dash:summary:{$username}:");
            $this->forgetCacheByPattern("dash:interactive:{$username}:");

            // Extrato e lista de transações (chaves compostas com parâmetros)
            $this->forgetCacheByPattern("user_transactions_{$username}_");
            $this->forgetCacheByPattern("extrato:{$username}:");

            // Gamificação / jornada
            Cache::forget("gamification_data_user_{$userNumericId}");
            $this->forgetCacheByPattern("sidebar_gamification_user_{$userNumericId}");

            Cache::forget("user_profile_{$username}");
            app(\App\Services\QRCodeService::class)->clearUserCache($username);

            $this->forgetCacheByPattern('admin:dashboard:stats:');
            $this->forgetCacheByPattern('admin:transactions:recent:');
            Cache::forget('admin:users:stats');
            Cache::forget('total:wallets:balance');

            if (config('cache.default') !== 'redis') {
                $now = \Carbon\Carbon::now();
                $adminPeriods = [
                    'hoje'         => [$now->copy()->startOfDay(),                  $now->copy()->endOfDay()],
                    'ontem'        => [$now->copy()->subDay()->startOfDay(),         $now->copy()->subDay()->endOfDay()],
                    '7dias'        => [$now->copy()->subDays(6)->startOfDay(),       $now->copy()->endOfDay()],
                    '30dias'       => [$now->copy()->subDays(29)->startOfDay(),      $now->copy()->endOfDay()],
                    'mes_atual'    => [$now->copy()->startOfMonth(),                 $now->copy()->endOfMonth()],
                    'mes_anterior' => [$now->copy()->subMonth()->startOfMonth(),     $now->copy()->subMonth()->endOfMonth()],
                    'tudo'         => [$now->copy()->subYears(100)->startOfDay(),    $now->copy()->endOfDay()],
                ];
                foreach ($adminPeriods as $p => [$inicio, $fim]) {
                    Cache::forget(\App\Services\CacheKeyService::adminDashboardStats($p, $inicio, $fim));
                }
                \App\Services\CacheKeyService::forgetAdminRecentTransactions();
            }

            $financialService = app(\App\Services\FinancialService::class);
            $financialService->invalidateWalletsCache();
            $financialService->invalidateStatsCache();

            Log::debug("Caches invalidados após pagamento", ['user_id' => $userId, 'username' => $username, 'id' => $userNumericId]);
        } catch (\Throwable $e) {
            // Nunca deixar falha de cache quebrar o fluxo de pagamento
            Log::warning("Erro ao invalidar caches após pagamento", [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Invalida caches de listagem e detalhe de infrações PIX (após processar webhook de infração).
     */
    public function invalidateInfractionCaches(string $username): void
    {
        try {
            $this->forgetCacheByPattern("pix_infracoes:{$username}:");
            $this->forgetCacheByPattern("pix_infracao_detail:{$username}:");
            Log::debug("Caches de infrações invalidados", ['username' => $username]);
        } catch (\Throwable $e) {
            Log::debug("invalidateInfractionCaches falhou silenciosamente", [
                'username' => $username,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Remove chaves de cache pelo prefixo usando Redis (quando disponível) ou fallback por lista fixa de períodos.
     */
    private function forgetCacheByPattern(string $prefix): void
    {
        try {
            if (config('cache.default') === 'redis') {
                $redis = \Illuminate\Support\Facades\Redis::connection(
                    config('cache.stores.redis.connection', 'cache')
                );
                $cachePrefix = config('cache.prefix', '');
                $pattern = ($cachePrefix ? $cachePrefix . ':' : '') . $prefix . '*';
                $keys = $redis->keys($pattern);
                if (!empty($keys)) {
                    $redis->del($keys);
                }
            } else {
                // Fallback para drivers sem suporte a padrão: limpar combinações conhecidas de período
                foreach (['hoje', 'ontem', '7dias', '30dias'] as $periodo) {
                    Cache::forget($prefix . $periodo);
                }
            }
        } catch (\Throwable $e) {
            Log::debug("forgetCacheByPattern falhou silenciosamente", ['prefix' => $prefix, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Processa saque de forma atômica
     */
    public function processWithdrawal(SolicitacoesCashOut $cashout): void
    {
        DB::transaction(function () use ($cashout) {
            // Lock no registro do saque
            $cashout = \App\Models\SolicitacoesCashOut::where('id', $cashout->id)
                ->lockForUpdate()
                ->first();
            
            if (!$cashout) {
                throw new \Exception("Saque não encontrado: {$cashout->id}");
            }
            
            // Verificar idempotência
            if ($cashout->status === 'COMPLETED' || $cashout->status === 'PAID_OUT') {
                Log::info("Saque já processado anteriormente", [
                    'transaction_id' => $cashout->idTransaction,
                    'status' => $cashout->status,
                ]);
                return;
            }
            
            // Lock no usuário
            $user = User::where('user_id', $cashout->user_id)
                ->lockForUpdate()
                ->first();
            
            if (!$user) {
                throw new \Exception("Usuário não encontrado: {$cashout->user_id}");
            }
            
            $balanceBefore = $user->saldo;

            // 1. Atualizar status
            $cashout->update(['status' => 'COMPLETED']);

            $balanceAfter = $user->fresh()->saldo;
            
            // 3. Registrar evento
            $this->eventService->recordPaymentSent(
                $cashout,
                $user,
                $balanceBefore,
                $balanceAfter
            );
            
            // 4. Processar comissões de afiliados (dentro da transação)
            if ($user->affiliate_id) {
                try {
                    $affiliateService = app(\App\Services\AffiliateCommissionService::class);
                    $affiliateService->processCashOutCommission($cashout, $user);
                } catch (\Exception $e) {
                    Log::error("Erro ao processar comissão de afiliado (cash-out)", [
                        'transaction_id' => $cashout->idTransaction,
                        'error' => $e->getMessage(),
                    ]);
                    // Não re-throw - comissões são opcionais, não devem quebrar o saque
                }
            }
            
            Log::info("Saque processado com sucesso", [
                'transaction_id' => $cashout->idTransaction,
                'user_id' => $user->user_id,
                'amount' => $cashout->amount,
                'taxa' => $cashout->taxa_cash_out,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
            ]);
        });

        $this->invalidateCachesAfterPayment($cashout->user_id);
    }
    
    /**
     * Processa splits dentro da transação
     */
    private function processSplits(Solicitacoes $cashin, User $user): void
    {
        try {
            if (trait_exists(SplitTrait::class)) {
                SplitTrait::processSplits($cashin, $user);
            }
        } catch (\Exception $e) {
            Log::error("Erro ao processar splits", [
                'transaction_id' => $cashin->idTransaction,
                'error' => $e->getMessage(),
            ]);
            // Não re-throw - splits são opcionais, não devem quebrar o pagamento
        }
    }
    
    /**
     * Processa comissões de gerente dentro da transação
     */
    private function processCommissions(Solicitacoes $cashin, User $user): void
    {
        try {
            if (!$user->gerente_id) {
                return;
            }
            
            $gerente = User::where('id', $user->gerente_id)
                ->lockForUpdate()
                ->first();
            
            if (!$gerente) {
                Log::warning("Gerente não encontrado", [
                    'gerente_id' => $user->gerente_id,
                ]);
                return;
            }
            
            $gerentePorcentagem = $gerente->gerente_percentage ?? 0;
            
            if ($gerentePorcentagem > 0) {
                $valorComissao = (float) $cashin->taxa_cash_in * ($gerentePorcentagem / 100);
                
                // Criar registro de comissão
                \App\Models\Transactions::create([
                    'user_id' => $user->user_id,
                    'gerente_id' => $user->gerente_id,
                    'solicitacao_id' => $cashin->id,
                    'comission_value' => $valorComissao,
                    'transaction_percent' => $cashin->taxa_cash_in,
                    'comission_percent' => $gerentePorcentagem,
                ]);
                
                Log::info("Comissão de gerente processada", [
                    'gerente_id' => $gerente->id,
                    'valor_comissao' => $valorComissao,
                ]);
            }
        } catch (\Exception $e) {
            Log::error("Erro ao processar comissão de gerente", [
                'transaction_id' => $cashin->idTransaction,
                'error' => $e->getMessage(),
            ]);
            // Não re-throw - comissões são opcionais
        }
    }
}
