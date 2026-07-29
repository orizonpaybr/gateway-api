<?php

namespace App\Services\FluxPayments;

class FluxPaymentsAuthService
{
    /**
     * @param  array<string, mixed>|null  $credentials  Credenciais de uma conta/nominal
     *                                                  específica (linha `adquirentes.credentials`). Quando nulo, cai no
     *                                                  comportamento global de sempre: lê do .env via config('<configKey>.*').
     * @param  string  $configKey  Arquivo de config do provider desta família de API
     *                             (fluxpayments | paya55) — a API é a mesma, só mudam host e credenciais.
     */
    public function __construct(
        private readonly ?array $credentials = null,
        protected readonly string $configKey = 'fluxpayments',
    ) {}

    private function value(string $key, mixed $default = null): mixed
    {
        return $this->credentials[$key] ?? config("{$this->configKey}.$key", $default);
    }

    /**
     * Headers de autenticação Basic + User-Agent obrigatório da adquirente.
     *
     * @return array{Authorization: string, User-Agent: string, Accept: string, Content-Type: string}
     */
    public function authHeaders(): array
    {
        $apiKey = (string) $this->value('api_key', '');
        $publicKey = (string) $this->value('public_key', '');

        if ($apiKey === '' || $publicKey === '') {
            $env = strtoupper($this->configKey);

            throw new \RuntimeException("{$this->configKey}: {$env}_API_KEY / {$env}_PUBLIC_KEY não configuradas.");
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
