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
        if (str_contains($path, 'heartpay')) return 'heartpay';
        return 'unknown';
    }

    private function validateWebhookSignature(Request $request, string $adquirente): bool
    {
        switch ($adquirente) {
            case 'pagarme':
                return $this->validatePagarmeWebhook($request);
            case 'heartpay':
                return $this->validateHeartPayWebhook($request);
            default:
                // Para adquirentes desconhecidos, rejeitar em produção
                return !app()->environment('production');
        }
    }
    
    /**
     * Valida webhook da HeartPay.
     *
     * HeartPay utiliza HMAC-SHA256 com a API Key para assinar webhooks.
     * Validação: IP whitelist → HMAC → estrutura do payload.
     */
    private function validateHeartPayWebhook(Request $request): bool
    {
        $whitelistedIps = config('heartpay.webhook_ips', []);

        if (!empty($whitelistedIps)) {
            $requestIp = $request->ip();
            $ipValid = false;

            foreach ($whitelistedIps as $allowedIp) {
                if (str_contains($allowedIp, '/')) {
                    if ($this->ipInRange($requestIp, $allowedIp)) {
                        $ipValid = true;
                        break;
                    }
                } elseif ($requestIp === trim($allowedIp)) {
                    $ipValid = true;
                    break;
                }
            }

            if (!$ipValid) {
                Log::warning('ValidateHeartPayWebhook - IP não autorizado', [
                    'ip' => $requestIp,
                    'allowed_ips' => $whitelistedIps,
                ]);
                return false;
            }
        }

        $webhookSecret = config('heartpay.webhook_secret');

        if (!empty($webhookSecret)) {                                                                                                                                                                   
            $tokenHeader = $request->header('x-webhook-token');
            if ($tokenHeader !== null && hash_equals($webhookSecret, trim($tokenHeader))) {
                Log::debug('ValidateHeartPayWebhook - Token x-webhook-token válido');
                return true;
            }

            $signature = $request->header('X-HeartPay-Signature');
            $timestamp = $request->header('X-HeartPay-Timestamp');

            if (!$signature) {
                Log::warning('ValidateHeartPayWebhook - Header X-HeartPay-Signature ausente', [
                    'headers' => array_keys($request->headers->all()),
                ]);
                return !app()->environment('production');
            }

            if ($timestamp) {
                $age = time() - (int) $timestamp;
                if ($age > 300) {
                    Log::warning('ValidateHeartPayWebhook - Webhook expirado (> 5 min)', [
                        'timestamp' => $timestamp,
                        'age_seconds' => $age,
                    ]);
                    return false;
                }
            }

            $rawBody = $request->getContent();
            $signedPayload = $timestamp ? ($timestamp . '.' . $rawBody) : $rawBody;
            $expected = hash_hmac('sha256', $signedPayload, $webhookSecret);

            if (!hash_equals($expected, $signature)) {
                Log::warning('ValidateHeartPayWebhook - Assinatura HMAC inválida');
                return false;
            }

            Log::debug('ValidateHeartPayWebhook - Assinatura HMAC válida');
            return true;
        }

        if (app()->environment('production') && empty($webhookSecret)) {
            Log::warning('ValidateHeartPayWebhook - HEARTPAY_WEBHOOK_SECRET não configurado em produção');
        }

        return true;
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
