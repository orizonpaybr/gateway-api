<?php

namespace App\Console\Commands;

use App\Constants\AuthEventType;
use App\Services\AuthAuditService;
use Illuminate\Console\Command;

class AuthSecurityReportCommand extends Command
{
    protected $signature = 'auth:security-report {--hours=24 : Período em horas}';

    protected $description = 'Relatório de eventos de autenticação para auditoria';

    public function handle(AuthAuditService $audit): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $minutes = $hours * 60;

        $loginFailures = $audit->countRecent(AuthEventType::LOGIN_FAILED, $minutes);
        $twoFaFailures = $audit->countRecent(AuthEventType::TWO_FA_FAILED, $minutes);
        $sessionTerminated = $audit->countRecent(AuthEventType::TWO_FA_SESSION_TERMINATED, $minutes);
        $topIps = $audit->topIpsByEventTypes([
            AuthEventType::LOGIN_FAILED,
            AuthEventType::TWO_FA_FAILED,
        ], $minutes, 10);

        $this->info("Relatório de autenticação — últimas {$hours}h");
        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Logins bem-sucedidos', $audit->countRecent(AuthEventType::LOGIN_SUCCESS, $minutes)],
                ['Falhas de login', $loginFailures],
                ['Falhas de 2FA', $twoFaFailures],
                ['Sessões 2FA encerradas', $sessionTerminated],
                ['Contas bloqueadas', $audit->countRecent(AuthEventType::ACCOUNT_LOCKED, $minutes)],
            ],
        );

        if (! empty($topIps)) {
            $this->newLine();
            $this->info('Top IPs com falhas:');
            $rows = [];
            foreach ($topIps as $ip => $count) {
                $rows[] = [$ip, $count];
            }
            $this->table(['IP', 'Falhas'], $rows);
        }

        return self::SUCCESS;
    }
}
