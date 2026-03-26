<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;

/**
 * Middleware para validação de webhooks de adquirentes
 * 
 * Cada adquirente pode ter um método diferente de validação de assinatura.
 * Este middleware centraliza a validação para garantir que webhooks
 * são autênticos e não foram adulterados.
 */
class ValidateWebhook
{
    public function handle(Request $request, Closure $next)
    {
        // Verificar se é um webhook de teste (apenas em ambiente não-produção)
        if (!app()->environment('production') && $request->has('test_webhook') && $request->get('test_webhook') === 'true') {
            Log::debug('ValidateWebhook - Webhook de teste aceito', [
                'ip' => $request->ip(),
            ]);
            return $next($request);
        }

        // Validar assinatura do webhook baseada no adquirente
        $adquirente = $this->detectAdquirente($request);
        
        if (!$this->validateWebhookSignature($request, $adquirente)) {
            // Capturar conteúdo completo do webhook inválido para auditoria
            $webhookData = [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'adquirente' => $adquirente,
                'timestamp' => now()->toIso8601String(),
                'method' => $request->method(),
                'url' => $request->fullUrl(),
                'headers' => $this->sanitizeHeaders($request->headers->all()),
                'body_preview' => substr($request->getContent(), 0, 500),
            ];
            
            Log::warning('ValidateWebhook - Webhook inválido recebido', $webhookData);
            
            // Em produção, rejeitar webhooks inválidos
            if (app()->environment('production')) {
                return response()->json([
                    'status' => 'error', 
                    'message' => 'Invalid webhook signature'
                ], 401);
            }
            
            // Em outros ambientes, logar mas aceitar (para testes)
            Log::info('ValidateWebhook - Webhook aceito em ambiente de desenvolvimento', [
                'adquirente' => $adquirente,
            ]);
        }

        return $next($request);
    }

    private function detectAdquirente(Request $request): string
    {
        $path = $request->path();
        
        if (str_contains($path, 'pagarme')) return 'pagarme';
        return 'unknown';
    }

    private function validateWebhookSignature(Request $request, string $adquirente): bool
    {
        switch ($adquirente) {
            case 'pagarme':
                return $this->validatePagarmeWebhook($request);
            default:
                // Para adquirentes desconhecidos, rejeitar em produção
                return !app()->environment('production');
        }
    }
    
    private function validatePagarmeWebhook(Request $request): bool
    {
        $pagarme = \App\Models\Pagarme::first();
        if (!$pagarme || !$pagarme->webhook_secret) {
            Log::warning('ValidatePagarmeWebhook - Webhook secret não configurado');
            return false;
        }

        $signature = $request->header('X-Pagarme-Signature');
        
        if (!$signature) {
            Log::warning('ValidatePagarmeWebhook - Header de assinatura não encontrado');
            return false;
        }
        
        $payload = $request->getContent();
        $expectedSignature = hash_hmac('sha256', $payload, $pagarme->webhook_secret);
        
        // Comparação segura contra timing attacks
        return hash_equals($expectedSignature, $signature);
    }
    
    /**
     * Verifica se um IP está dentro de um range CIDR
     */
    private function ipInRange(string $ip, string $cidr): bool
    {
        list($subnet, $mask) = explode('/', $cidr);
        
        $ip = ip2long($ip);
        $subnet = ip2long($subnet);
        $mask = -1 << (32 - (int)$mask);
        
        $subnet &= $mask;
        
        return ($ip & $mask) === $subnet;
    }
    
    /**
     * Remove dados sensíveis dos headers para log
     */
    private function sanitizeHeaders(array $headers): array
    {
        $sensitiveKeys = ['authorization', 'x-api-key', 'cookie', 'x-auth-token'];
        
        foreach ($headers as $key => $value) {
            if (in_array(strtolower($key), $sensitiveKeys)) {
                $headers[$key] = ['[REDACTED]'];
            }
        }
        
        return $headers;
    }
}
