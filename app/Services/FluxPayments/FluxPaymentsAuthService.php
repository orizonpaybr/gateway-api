<?php

namespace App\Services\FluxPayments;

class FluxPaymentsAuthService
{
    /**
     * Headers de autenticação Basic + User-Agent obrigatório da FluxPayments.
     *
     * @return array{Authorization: string, User-Agent: string, Accept: string, Content-Type: string}
     */
    public function authHeaders(): array
    {
        $apiKey = (string) config('fluxpayments.api_key', '');
        $publicKey = (string) config('fluxpayments.public_key', '');

        if ($apiKey === '' || $publicKey === '') {
            throw new \RuntimeException('FluxPayments: FLUXPAYMENTS_API_KEY / FLUXPAYMENTS_PUBLIC_KEY não configuradas.');
        }

        return [
            'Authorization' => 'Basic '.base64_encode($apiKey.':'.$publicKey),
            'User-Agent' => (string) config('fluxpayments.user_agent', 'CoratriGateway/1.0 (+contato@coratri.com.br)'),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    public function isConfigured(): bool
    {
        return trim((string) config('fluxpayments.api_key', '')) !== ''
            && trim((string) config('fluxpayments.public_key', '')) !== '';
    }
}
