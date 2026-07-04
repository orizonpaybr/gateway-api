<?php

namespace App\Console\Commands;

use App\Models\AuthEvent;
use Illuminate\Console\Command;

class AuthSecurityPruneEventsCommand extends Command
{
    protected $signature = 'auth:prune-events {--days= : Dias de retenção (padrão: config auth_events_retention_days)}';

    protected $description = 'Remove eventos de autenticação antigos da tabela auth_events';

    public function handle(): int
    {
        $days = (int) ($this->option('days')
            ?: config('auth_security.auth_events_retention_days', 90));
        $days = max(1, $days);

        $cutoff = now()->subDays($days);
        $deleted = AuthEvent::query()
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info("Removidos {$deleted} evento(s) anteriores a {$cutoff->toDateTimeString()}.");

        return self::SUCCESS;
    }
}
