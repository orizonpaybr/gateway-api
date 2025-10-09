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
        
        if (str_contains($path, 'pixup')) return 'pixup';
        if (str_contains($path, 'bspay')) return 'bspay';
        if (str_contains($path, 'woovi')) return 'woovi';
        if (str_contains($path, 'efi')) return 'efi';
        if (str_contains($path, 'xgate')) return 'xgate';
        if (str_contains($path, 'cashtime')) return 'cashtime';
        if (str_contains($path, 'mercadopago')) return 'mercadopago';
        if (str_contains($path, 'pagarme')) return 'pagarme';
        if (str_contains($path, 'witetec')) return 'witetec';
        if (str_contains($path, 'primepay7')) return 'primepay7';
        if (str_contains($path, 'xdpag')) return 'xdpag';
        return 'unknown';
    }

    private function validateWebhookSignature(Request $request, string $adquirente): bool
    {
        switch ($adquirente) {
            case 'woovi':
                return $this->validateWooviWebhook($request);
            case 'pixup':
                return $this->validatePixupWebhook($request);
            case 'bspay':
                return $this->validateBSPayWebhook($request);
            case 'efi':
                return $this->validateEfiWebhook($request);
            case 'xgate':
                return $this->validateXgateWebhook($request);
            case 'cashtime':
                return $this->validateCashtimeWebhook($request);
            case 'mercadopago':
                return $this->validateMercadoPagoWebhook($request);
            case 'pagarme':
                return $this->validatePagarmeWebhook($request);
            case 'witetec':
                return $this->validateWitetecWebhook($request);
            case 'primepay7':
                return $this->validatePrimePay7Webhook($request);
            case 'xdpag':
                return $this->validateXDPagWebhook($request);
            default:
                // Para adquirentes desconhecidos, aceitar apenas em ambiente de desenvolvimento
                return app()->environment('local', 'development');
        }
    }

    private function validateWooviWebhook(Request $request): bool
    {
        $woovi = \App\Models\Woovi::first();
        if (!$woovi || !$woovi->webhook_secret) {
            return false;
        }

        $authorization = $request->get('authorization');
        return $authorization === $woovi->webhook_secret;
    }

    private function validatePixupWebhook(Request $request): bool
    {
        $pixup = \App\Models\Pixup::first();
        if (!$pixup || !$pixup->webhook_secret) {
            return false;
        }

        $signature = $request->header('X-Pixup-Signature');
        $payload = $request->getContent();
        $expectedSignature = hash_hmac('sha256', $payload, $pixup->webhook_secret);
        
        return hash_equals($expectedSignature, $signature);
    }

    private function validateBSPayWebhook(Request $request): bool
    {
        $bspay = \App\Models\BSPay::first();
        if (!$bspay || !$bspay->webhook_secret) {
            return false;
        }

        $signature = $request->header('X-BSPay-Signature');
        $payload = $request->getContent();
        $expectedSignature = hash_hmac('sha256', $payload, $bspay->webhook_secret);
        
        return hash_equals($expectedSignature, $signature);
    }

    private function validateEfiWebhook(Request $request): bool
    {
        $efi = \App\Models\Efi::first();
        if (!$efi || !$efi->webhook_secret) {
            return false;
        }

        $signature = $request->header('X-EFI-Signature');
        $payload = $request->getContent();
        $expectedSignature = hash_hmac('sha256', $payload, $efi->webhook_secret);
        
        return hash_equals($expectedSignature, $signature);
    }

    private function validateXgateWebhook(Request $request): bool
    {
        $xgate = \App\Models\Xgate::first();
        if (!$xgate || !$xgate->webhook_secret) {
            return false;
        }

        $signature = $request->header('X-XGate-Signature');
        $payload = $request->getContent();
        $expectedSignature = hash_hmac('sha256', $payload, $xgate->webhook_secret);
        
        return hash_equals($expectedSignature, $signature);
    }

    private function validateCashtimeWebhook(Request $request): bool
    {
        $cashtime = \App\Models\Cashtime::first();
        if (!$cashtime || !$cashtime->webhook_secret) {
            return false;
        }

        $signature = $request->header('X-Cashtime-Signature');
        $payload = $request->getContent();
        $expectedSignature = hash_hmac('sha256', $payload, $cashtime->webhook_secret);
        
        return hash_equals($expectedSignature, $signature);
    }

    private function validateMercadoPagoWebhook(Request $request): bool
    {
        $mercadopago = \App\Models\AdMercadopago::first();
        if (!$mercadopago || !$mercadopago->webhook_secret) {
            return false;
        }

        $signature = $request->header('X-MercadoPago-Signature');
        $payload = $request->getContent();
        $expectedSignature = hash_hmac('sha256', $payload, $mercadopago->webhook_secret);
        
        return hash_equals($expectedSignature, $signature);
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

    private function validateWitetecWebhook(Request $request): bool
    {
        $witetec = \App\Models\Witetec::first();
        if (!$witetec || !$witetec->webhook_secret) {
            return false;
        }

        $signature = $request->header('X-Witetec-Signature');
        $payload = $request->getContent();
        $expectedSignature = hash_hmac('sha256', $payload, $witetec->webhook_secret);
        
        return hash_equals($expectedSignature, $signature);
    }

    private function validatePrimePay7Webhook(Request $request): bool
    {
        // A PrimePay7 não usa assinatura de webhook baseada na documentação
        // Vamos validar apenas se a requisição tem os campos necessários
        $data = $request->all();
        
        // Log detalhado para debug
        Log::info('PrimePay7 Webhook Validation', [
            'data_received' => $data,
            'raw_content' => $request->getContent(),
            'headers' => $request->headers->all(),
            'method' => $request->method(),
            'url' => $request->fullUrl()
        ]);
        
        // Verificar se tem pelo menos um campo identificador
        $hasIdentifier = isset($data['id']) || 
                        isset($data['externalId']) || 
                        isset($data['external_id']) ||
                        isset($data['transaction_id']);
        
        // Verificar se tem status (pode estar no nível raiz ou dentro de data)
        $hasStatus = isset($data['status']) || 
                     (isset($data['data']) && isset($data['data']['status']));
        
        // Log do resultado da validação
        Log::info('PrimePay7 Webhook Validation Result', [
            'hasIdentifier' => $hasIdentifier,
            'hasStatus' => $hasStatus,
            'isValid' => $hasIdentifier && $hasStatus,
            'available_fields' => array_keys($data)
        ]);
        
        // Aceitar se tem pelo menos um identificador e status
        return $hasIdentifier && $hasStatus;
    }

    private function validateXDPagWebhook(Request $request): bool
    {
        $xdpag = \App\Models\XDPag::first();
        if (!$xdpag) {
            Log::warning('XDPag webhook validation failed: No XDPag configuration found');
            return false;
        }

        // Log detalhado para debug
        Log::info('XDPag Webhook Validation', [
            'data_received' => $request->all(),
            'raw_content' => $request->getContent(),
            'headers' => $request->headers->all(),
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'xdpag_config_exists' => $xdpag ? true : false
        ]);

        // A XDPag pode usar diferentes métodos de validação
        // Vamos tentar validar por assinatura se existir webhook_secret
        if (isset($xdpag->webhook_secret) && $xdpag->webhook_secret) {
            $signature = $request->header('X-XDPag-Signature') ?? $request->header('X-Signature');
            $payload = $request->getContent();
            $expectedSignature = hash_hmac('sha256', $payload, $xdpag->webhook_secret);
            
            $isValid = hash_equals($expectedSignature, $signature);
            
            Log::info('XDPag Webhook Signature Validation', [
                'signature_received' => $signature,
                'expected_signature' => $expectedSignature,
                'is_valid' => $isValid
            ]);
            
            return $isValid;
        }

        // Se não tem webhook_secret, validar por estrutura do payload
        $data = $request->all();
        
        // Verificar se tem pelo menos um campo identificador
        $hasIdentifier = isset($data['id']) || 
                        isset($data['externalId']) || 
                        isset($data['external_id']) ||
                        isset($data['transaction_id']) ||
                        isset($data['order_id']) ||
                        (isset($data['data']) && (
                            isset($data['data']['id']) ||
                            isset($data['data']['externalId']) ||
                            isset($data['data']['external_id'])
                        ));
        
        // Verificar se tem status (pode estar no nível raiz ou dentro de data)
        $hasStatus = isset($data['status']) || 
                     (isset($data['data']) && isset($data['data']['status']));
        
        // Log do resultado da validação
        Log::info('XDPag Webhook Structure Validation', [
            'hasIdentifier' => $hasIdentifier,
            'hasStatus' => $hasStatus,
            'isValid' => $hasIdentifier && $hasStatus,
            'available_fields' => array_keys($data)
        ]);
        
        // Aceitar se tem pelo menos um identificador e status
        return $hasIdentifier && $hasStatus;
    }
}
