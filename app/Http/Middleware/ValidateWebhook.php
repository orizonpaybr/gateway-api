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
        if (str_contains($path, 'treeal')) return 'treeal';
        return 'unknown';
    }

    private function validateWebhookSignature(Request $request, string $adquirente): bool
    {
        switch ($adquirente) {
            case 'pagarme':
                return $this->validatePagarmeWebhook($request);
            case 'treeal':
                return $this->validateTreealWebhook($request);
            default:
                // Para adquirentes desconhecidos, rejeitar em produção
                return !app()->environment('production');
        }
    }
    
    /**
     * Valida assinatura do webhook da Treeal/ONZ
     * 
     * Implementa múltiplos métodos de validação:
     * 1. HMAC SHA256 com webhook secret (se configurado)
     * 2. Validação de IP de origem (whitelist)
     * 3. Validação de estrutura do payload
     * 
     * IMPORTANTE: Ative a validação HMAC assim que a TREEAL fornecer
     * a documentação de assinatura de webhooks.
     */
    private function validateTreealWebhook(Request $request): bool
    {
        $webhookSecret = config('treeal.webhook_secret');
        $whitelistedIps = config('treeal.webhook_ips', []);
        
        // 1. Validação de IP (se configurado)
        if (!empty($whitelistedIps)) {
            $requestIp = $request->ip();
            $ipValid = false;
            
            foreach ($whitelistedIps as $allowedIp) {
                // Suporte a ranges CIDR (ex: 192.168.1.0/24)
                if (str_contains($allowedIp, '/')) {
                    if ($this->ipInRange($requestIp, $allowedIp)) {
                        $ipValid = true;
                        break;
                    }
                } elseif ($requestIp === $allowedIp) {
                    $ipValid = true;
                    break;
                }
            }
            
            if (!$ipValid) {
                Log::warning('ValidateTreealWebhook - IP não autorizado', [
                    'ip' => $requestIp,
                    'allowed_ips' => $whitelistedIps,
                ]);
                return false;
            }
            
            Log::debug('ValidateTreealWebhook - IP autorizado', ['ip' => $requestIp]);
        }
        
        // 2. Validação HMAC (se webhook secret configurado)
        if (!empty($webhookSecret)) {
            // Procurar assinatura em headers comuns
            $signature = $request->header('X-Webhook-Signature') 
                ?? $request->header('X-Signature')
                ?? $request->header('X-Treeal-Signature')
                ?? $request->header('Signature');
                
            if (!$signature) {
                Log::warning('ValidateTreealWebhook - Assinatura não encontrada no header', [
                    'headers' => array_keys($request->headers->all()),
                ]);
                // Se não há assinatura mas secret está configurado, rejeitar em produção
                return !app()->environment('production');
            }
            
            $payload = $request->getContent();
            $expectedSignature = hash_hmac('sha256', $payload, $webhookSecret);
            
            // Comparação segura contra timing attacks
            if (!hash_equals($expectedSignature, $signature)) {
                Log::warning('ValidateTreealWebhook - Assinatura inválida', [
                    'expected_prefix' => substr($expectedSignature, 0, 10) . '...',
                    'received_prefix' => substr($signature, 0, 10) . '...',
                ]);
                return false;
            }
            
            Log::debug('ValidateTreealWebhook - Assinatura válida');
            return true;
        }
        
        // 3. Validação de estrutura do payload (validação mínima)
        $payload = $request->all();
        
        // Verificar se tem campos esperados de webhook TREEAL
        // Cash In: txid ou txId
        // Cash Out: transactionId ou endToEndId
        $hasCashInFields = isset($payload['txid']) || isset($payload['txId']);
        $hasCashOutFields = isset($payload['transactionId']) || isset($payload['endToEndId']);
        $hasStatusField = isset($payload['status']);
        
        if (!$hasCashInFields && !$hasCashOutFields) {
            Log::warning('ValidateTreealWebhook - Payload não reconhecido como webhook TREEAL', [
                'fields' => array_keys($payload),
            ]);
            // Não rejeitar automaticamente - pode ser um novo formato
        }
        
        // Log de auditoria
        Log::info('ValidateTreealWebhook - Webhook recebido', [
            'has_cash_in_fields' => $hasCashInFields,
            'has_cash_out_fields' => $hasCashOutFields,
            'has_status' => $hasStatusField,
            'ip' => $request->ip(),
            'validation_mode' => empty($webhookSecret) ? 'structure_only' : 'hmac',
        ]);
        
        // Se não há webhook secret configurado, aceitar com validação básica
        // ALERTA: Configure TREEAL_WEBHOOK_SECRET em produção quando disponível
        if (empty($webhookSecret) && app()->environment('production')) {
            Log::warning('ValidateTreealWebhook - ATENÇÃO: TREEAL_WEBHOOK_SECRET não configurado em produção!', [
                'recommendation' => 'Configure TREEAL_WEBHOOK_SECRET no .env quando a TREEAL fornecer a documentação',
            ]);
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
