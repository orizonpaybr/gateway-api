<?php

namespace App\Services;

use App\Models\BalanceLedgerEntry;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Auditoria persistente de movimento de saldo (não depende de log rotacionado).
 */
class BalanceLedgerService
{
    /**
     * @param  array{
     *   reason: string,
     *   source?: string|null,
     *   actor_id?: int|null,
     *   ref_type?: string|null,
     *   ref_id?: string|int|null,
     *   meta?: array<string, mixed>|null
     * }  $context
     */
    public static function record(
        User $user,
        string $field,
        float $delta,
        float $balanceBefore,
        float $balanceAfter,
        array $context,
    ): void {
        $reason = trim((string) ($context['reason'] ?? ''));
        if ($reason === '' || abs($delta) < 0.00005) {
            return;
        }

        try {
            BalanceLedgerEntry::create([
                'user_id' => (int) $user->id,
                'username' => $user->username ?? $user->user_id,
                'field' => $field,
                'delta' => round($delta, 4),
                'balance_before' => round($balanceBefore, 4),
                'balance_after' => round($balanceAfter, 4),
                'reason' => substr($reason, 0, 64),
                'source' => isset($context['source']) ? substr((string) $context['source'], 0, 128) : null,
                'actor_id' => $context['actor_id'] ?? Auth::id(),
                'ref_type' => isset($context['ref_type']) ? substr((string) $context['ref_type'], 0, 64) : null,
                'ref_id' => isset($context['ref_id']) ? substr((string) $context['ref_id'], 0, 64) : null,
                'meta' => $context['meta'] ?? null,
            ]);
        } catch (\Throwable $e) {
            // Nunca abortar o movimento de saldo por falha de auditoria.
            Log::error('[BALANCE_LEDGER] Falha ao gravar auditoria', [
                'user_id' => $user->id,
                'field' => $field,
                'delta' => $delta,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
