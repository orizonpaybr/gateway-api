<?php

namespace App\Jobs;

use App\Constants\AuthEventType;
use App\Services\AuthAuditService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MonitorLoginFailuresJob implements ShouldQueue
{
    use Queueable;

    public function handle(AuthAuditService $audit): void
    {
        $windowMinutes = max(1, (int) config('auth_security.alert_window_minutes', 5));
        $loginThreshold = max(1, (int) config('auth_security.alert_failed_login_threshold', 50));
        $twoFaThreshold = max(1, (int) config('auth_security.alert_2fa_failed_threshold', 30));
        $ipThreshold = max(1, (int) config('auth_security.alert_ip_failed_threshold', 10));
        $webhook = config('auth_security.alert_slack_webhook');

        if (empty($webhook)) {
            return;
        }

        $authEventTypes = [
            AuthEventType::LOGIN_FAILED,
            AuthEventType::TWO_FA_FAILED,
            AuthEventType::TWO_FA_SESSION_TERMINATED,
        ];

        $loginFailures = $audit->countRecent(AuthEventType::LOGIN_FAILED, $windowMinutes);
        $twoFaFailures = $audit->countRecent(AuthEventType::TWO_FA_FAILED, $windowMinutes);
        $totalFailures = $audit->countRecentByEventTypes($authEventTypes, $windowMinutes);
        $topIps = $audit->topIpsByEventTypes($authEventTypes, $windowMinutes, 5);

        $suspiciousIps = array_filter(
            $topIps,
            fn (int $count) => $count >= $ipThreshold,
        );

        if ($loginFailures < $loginThreshold
            && $twoFaFailures < $twoFaThreshold
            && empty($suspiciousIps)) {
            return;
        }

        $lines = [
            ':warning: *Alerta de segurança — autenticação*',
            "Janela: últimos {$windowMinutes} min",
            "Falhas de login: {$loginFailures}",
            "Falhas de 2FA: {$twoFaFailures}",
            "Total (login + 2FA): {$totalFailures}",
        ];

        if (! empty($suspiciousIps)) {
            $lines[] = 'IPs suspeitos:';
            foreach ($suspiciousIps as $ip => $count) {
                $lines[] = "• `{$ip}` — {$count} falhas";
            }
        }

        try {
            Http::post($webhook, ['text' => implode("\n", $lines)]);
        } catch (\Throwable $e) {
            Log::channel('security')->error('Falha ao enviar alerta Slack', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
