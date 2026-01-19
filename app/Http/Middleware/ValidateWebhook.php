<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;

class ValidateWebhook
{
    public function handle(Request $request, Closure $next)
    {
        // Verificar se é um webhook de teste
        if ($request->has('test_webhook') && $request->get('test_webhook') === 'true') {
            return $next($request);
        }

        // Validar assinatura do webhook baseada no adquirente
        $adquirente = $this->detectAdquirente($request);
        
        if (!$this->validateWebhookSignature($request, $adquirente)) {
            // Capturar conteúdo completo do webhook inválido
            $webhookData = [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'adquirente' => $adquirente,
                'timestamp' => now(),
                'method' => $request->method(),
                'url' => $request->fullUrl(),
                'headers' => $request->headers->all(),
                'body' => $request->all(),
                'raw_content' => $request->getContent(),
                'query_params' => $request->query->all()
            ];
            
            Log::warning('Webhook inválido recebido - Dados completos', $webhookData);
            
            return response()->json(['status' => 'error', 'message' => 'Webhook inválido'], 401);
        }

        return $next($request);
    }

    private function detectAdquirente(Request $request): string
    {
        $path = $request->path();
        
        // Apenas Pagar.me permanece ativo
        if (str_contains($path, 'pagarme')) return 'pagarme';
        return 'unknown';
    }

    private function validateWebhookSignature(Request $request, string $adquirente): bool
    {
        switch ($adquirente) {
            case 'pagarme':
                return $this->validatePagarmeWebhook($request);
            default:
                // Para adquirentes desconhecidos, aceitar apenas em ambiente de desenvolvimento
                return app()->environment('local', 'development');
        }
    }

    private function validatePagarmeWebhook(Request $request): bool
    {
        $pagarme = \App\Models\Pagarme::first();
        if (!$pagarme || !$pagarme->webhook_secret) {
            return false;
        }

        $signature = $request->header('X-Pagarme-Signature');
        $payload = $request->getContent();
        $expectedSignature = hash_hmac('sha256', $payload, $pagarme->webhook_secret);
        
        return hash_equals($expectedSignature, $signature);
    }

}
