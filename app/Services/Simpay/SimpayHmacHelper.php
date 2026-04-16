<?php

namespace App\Services\Simpay;

class SimpayHmacHelper
{
    /**
     * Gera HMAC-SHA512 a partir de um payload (array) e da chave HMAC configurada.
     *
     * O JSON é normalizado (sem espaços após ':' e ',') conforme exigido pela API SIMPAY.
     */
    public static function generate(array $payload): string
    {
        $secretKey = (string) config('simpay.hmac_key');

        if (empty($secretKey)) {
            throw new \RuntimeException('SIMPAY HMAC key (SIMPAY_HMAC_KEY) não configurada.');
        }

        $json = self::normalizeJson($payload);

        return hash_hmac('sha512', $json, $secretKey);
    }

    /**
     * Normaliza o payload em JSON compacto sem espaços extras,
     * conforme especificação SIMPAY.
     */
    public static function normalizeJson(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $json = str_replace([': ', ', '], [':', ','], $json);

        return $json;
    }
}
