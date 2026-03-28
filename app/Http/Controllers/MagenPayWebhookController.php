<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessMagenPayWebhookJob;
use App\Services\WebhookService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MagenPayWebhookController extends Controller
{
    public function handle(Request $request, WebhookService $webhookService)
    {
        $secret = config('magenpay.webhook_secret');
        if ($secret !== null && trim((string) $secret) !== '') {
            if ($request->header('X-MagenPay-Webhook-Secret') !== $secret) {
                Log::warning('MagenPay webhook — secret inválido ou ausente', [
                    'ip' => $request->ip(),
                ]);

                return response()->json(['message' => 'Unauthorized'], 401);
            }
        }

        $payload = $request->all();
        if (! isset($payload['type'], $payload['data']) || ! is_array($payload['data'])) {
            Log::warning('MagenPay webhook — envelope inválido (esperado type + data)', [
                'keys' => array_keys($payload),
            ]);

            return response()->json(['received' => true, 'ignored' => true], 200);
        }

        return $webhookService->processWebhook($request, 'magenpay', function ($webhookLog) {
            ProcessMagenPayWebhookJob::dispatch($webhookLog->id);

            return [
                'async' => true,
                'response' => response()->json(['received' => true], 200),
            ];
        });
    }
}
