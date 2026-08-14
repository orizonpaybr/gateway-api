<?php

namespace App\Console\Commands;

use App\Services\Fyhub\FyhubPixAcquirerService;
use App\Services\FyhubContas\FyhubContasApiClient;
use Illuminate\Console\Command;

/**
 * Cadastra os webhooks da fyhub apontando para este ambiente:
 *  - API QRCode (cash-in): PUT /webhook/{chave} → a fyhub faz POST em {url}/pix,
 *    então registramos {APP_URL}/fyhub/webhook (cai em /fyhub/webhook/pix).
 *  - API Contas: POST /webhooks/{transfer,receive,refund,cashout} → POST direto na uri.
 *    Envia o header X-Webhook-Token (fyhub_contas.webhook_secret) para autenticação.
 *
 * Idempotente: a API Contas devolve 409 quando o webhook já existe (tratado como ok).
 */
class RegisterFyhubWebhooksCommand extends Command
{
    protected $signature = 'fyhub:register-webhooks
                            {--url= : URL base pública (default config app.url)}';

    protected $description = 'Cadastra os webhooks fyhub (QRCode cash-in + 4 Contas) apontando para este ambiente';

    /** path do endpoint Contas => tipo retornado pela API */
    private const CONTAS_WEBHOOKS = ['transfer', 'receive', 'refund', 'cashout'];

    public function handle(FyhubPixAcquirerService $qr, FyhubContasApiClient $contas): int
    {
        $appUrl = rtrim((string) ($this->option('url') ?: config('app.url')), '/');
        if ($appUrl === '' || ! preg_match('#^https?://#i', $appUrl)) {
            $this->error('URL base inválida. Use --url=https://seu-dominio ou configure APP_URL.');

            return self::FAILURE;
        }

        $ok = $this->registerQrCode($qr, $appUrl.'/fyhub/webhook');
        $ok = $this->registerContas($contas, $appUrl.'/fyhub/contas/webhook') && $ok;

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function registerQrCode(FyhubPixAcquirerService $qr, string $webhookUrl): bool
    {
        $this->line("QRCode cash-in → {$webhookUrl} (fyhub faz POST em {$webhookUrl}/pix)");

        $res = $qr->setWebhookPix($webhookUrl);
        if ($res['success'] ?? false) {
            $this->info('  ok: webhook QRCode configurado.');

            return true;
        }

        $this->error('  falha QRCode: '.($res['message'] ?? 'erro desconhecido'));

        return false;
    }

    private function registerContas(FyhubContasApiClient $contas, string $uri): bool
    {
        $secret = trim((string) config('fyhub_contas.webhook_secret', ''));
        $headerName = (string) config('fyhub_contas.webhook_secret_header', 'X-Webhook-Token');

        if ($secret === '') {
            $this->warn("Contas: fyhub_contas.webhook_secret vazio — webhooks de cash-out ficarão SEM autenticação. Recomendado setar FYHUB_CONTAS_WEBHOOK_SECRET.");
        }

        $body = [
            'uri' => $uri,
            'method' => 'POST',
            'enabled' => true,
            'pauseOnFail' => true,
        ];
        if ($secret !== '') {
            $body['headers'] = [$headerName => $secret];
        }

        $allOk = true;
        foreach (self::CONTAS_WEBHOOKS as $type) {
            $this->line("Contas {$type} → {$uri}");

            try {
                $res = $contas->postJson('/webhooks/'.$type, $body);
                $status = $res->status();

                if ($status === 201 || $res->successful()) {
                    $this->info('  ok: cadastrado ('.$status.').');
                } elseif ($status === 409) {
                    $this->info('  ok: já existe (409).');
                } else {
                    $json = $res->json();
                    $msg = is_array($json) ? ($json['detail'] ?? $json['title'] ?? $json['message'] ?? 'erro') : 'erro';
                    $this->error("  falha ({$status}): {$msg}");
                    $allOk = false;
                }
            } catch (\Throwable $e) {
                $this->error('  exceção: '.$e->getMessage());
                $allOk = false;
            }
        }

        return $allOk;
    }
}
