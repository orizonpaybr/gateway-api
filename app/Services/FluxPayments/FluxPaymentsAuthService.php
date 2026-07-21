<?php

namespace App\Services\FluxPayments;

class FluxPaymentsAuthService
{
    /**
     * @param  array<string, mixed>|null  $credentials  Credenciais de uma conta/nominal
     *         específica (linha `adquirentes.credentials`). Quando nulo, cai no
     *         comportamento global de sempre: lê do .env via config('fluxpayments.*').
     */
    public function __construct(private readonly ?array $credentials = null)
    {
    }

    private function value(string $key, mixed $default = null): mixed
    {
        return $this->credentials[$key] ?? config("fluxpayments.$key", $default);
    }

    /**
     * Headers de autenticação Basic + User-Agent obrigatório da FluxPayments.
     *
     * @return array{Authorization: string, User-Agent: string, Accept: string, Content-Type: string}
     */
    public function authHeaders(): array
    {
        $apiKey = (string) $this->value('api_key', '');
        $publicKey = (string) $this->value('public_key', '');

        if ($apiKey === '' || $publicKey === '') {
            throw new \RuntimeException('FluxPayments: FLUXPAYMENTS_API_KEY / FLUXPAYMENTS_PUBLIC_KEY não configuradas.');
        }

        return [
            'Authorization' => 'Basic '.base64_encode($apiKey.':'.$publicKey),
            'User-Agent' => (string) $this->value('user_agent', 'CoratriGateway/1.0 (+contato@coratri.com.br)'),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    public function isConfigured(): bool
    {
        return trim((string) $this->value('api_key', '')) !== ''
            && trim((string) $this->value('public_key', '')) !== '';
    }

    public function webhookSecret(): string
    {
        return trim((string) $this->value('webhook_secret', ''));
    }

    public function webhookUrl(): string
    {
        return trim((string) $this->value('webhook_url', ''));
    }
}
