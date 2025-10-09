<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Log;

class SecureLog
{
    /**
     * Campos sensíveis que devem ser mascarados nos logs
     */
    private static array $sensitiveFields = [
        'token',
        'secret',
        'access_token',
        'client_secret',
        'webhook_secret',
        'authorization',
        'password',
        'cpf',
        'cnpj',
        'document',
        'card_number',
        'cvv',
        'pix_key',
        'qrcode',
        'paymentcode',
        'paymentCodeBase64',
        'encodedImage',
        'payload',
        'correlationID',
        'external_id',
        'idTransaction',
        'orderId',
        'data_id',
        'woovi_identifier'
    ];

    /**
     * Registra log de forma segura, mascarando dados sensíveis
     */
    public static function info(string $message, array $context = []): void
    {
        $maskedContext = self::maskSensitiveData($context);
        Log::info($message, $maskedContext);
    }

    /**
     * Registra log de debug de forma segura
     */
    public static function debug(string $message, array $context = []): void
    {
        $maskedContext = self::maskSensitiveData($context);
        Log::debug($message, $maskedContext);
    }

    /**
     * Registra log de erro de forma segura
     */
    public static function error(string $message, array $context = []): void
    {
        $maskedContext = self::maskSensitiveData($context);
        Log::error($message, $maskedContext);
    }

    /**
     * Registra log de warning de forma segura
     */
    public static function warning(string $message, array $context = []): void
    {
        $maskedContext = self::maskSensitiveData($context);
        Log::warning($message, $maskedContext);
    }

    /**
     * Mascara dados sensíveis em arrays
     */
    private static function maskSensitiveData(array $data): array
    {
        $masked = [];
        
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $masked[$key] = self::maskSensitiveData($value);
            } else {
                $masked[$key] = self::shouldMaskField($key) ? self::maskValue($value) : $value;
            }
        }
        
        return $masked;
    }

    /**
     * Verifica se um campo deve ser mascarado
     */
    private static function shouldMaskField(string $field): bool
    {
        $fieldLower = strtolower($field);
        
        foreach (self::$sensitiveFields as $sensitiveField) {
            if (str_contains($fieldLower, strtolower($sensitiveField))) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Mascara um valor sensível
     */
    private static function maskValue($value): string
    {
        if (is_null($value) || $value === '') {
            return '[VAZIO]';
        }
        
        if (is_numeric($value)) {
            return '[NUMERO]';
        }
        
        $stringValue = (string) $value;
        $length = strlen($stringValue);
        
        if ($length <= 4) {
            return str_repeat('*', $length);
        }
        
        // Mostra apenas os primeiros 2 e últimos 2 caracteres
        return substr($stringValue, 0, 2) . str_repeat('*', $length - 4) . substr($stringValue, -2);
    }

    /**
     * Log específico para webhooks com dados mascarados
     */
    public static function webhook(string $adquirente, string $action, array $data = []): void
    {
        $maskedData = self::maskSensitiveData($data);
        Log::info("[{$adquirente}][WEBHOOK][{$action}]", $maskedData);
    }

    /**
     * Log específico para transações com dados mascarados
     */
    public static function transaction(string $action, array $data = []): void
    {
        $maskedData = self::maskSensitiveData($data);
        Log::info("[TRANSACAO][{$action}]", $maskedData);
    }

    /**
     * Log específico para callbacks com dados mascarados
     */
    public static function callback(string $adquirente, string $action, array $data = []): void
    {
        $maskedData = self::maskSensitiveData($data);
        Log::info("[CALLBACK][{$adquirente}][{$action}]", $maskedData);
    }
}
