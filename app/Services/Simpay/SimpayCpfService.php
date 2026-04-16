<?php

namespace App\Services\Simpay;

use App\Helpers\SecureHttp;
use Illuminate\Support\Facades\Log;

class SimpayCpfService
{
    private SimpayAuthService $auth;
    private string $baseUrl;
    private int $timeout;

    public function __construct(SimpayAuthService $auth)
    {
        $this->auth = $auth;
        $this->baseUrl = rtrim((string) config('simpay.base_url'), '/');
        $this->timeout = (int) config('simpay.timeout', 30);
    }

    /**
     * Valida um CPF junto à API SIMPAY e retorna os dados associados.
     *
     * @param string $cpf Número do CPF (somente dígitos)
     * @return array{worked: bool, customer?: array, message?: string}
     */
    public function validate(string $cpf): array
    {
        $cpf = preg_replace('/\D/', '', $cpf);

        if (strlen($cpf) !== 11) {
            return [
                'worked' => false,
                'message' => 'CPF deve conter 11 dígitos.',
            ];
        }

        $url = $this->baseUrl . '/get-customer/?document_number=' . $cpf;

        try {
            $response = SecureHttp::post($url, [], $this->auth->authHeaders(), $this->timeout);

            $body = $response->json();

            if ($this->isTokenExpired($response, $body)) {
                $this->auth->invalidateToken();
                $response = SecureHttp::post($url, [], $this->auth->authHeaders(), $this->timeout);
                $body = $response->json();
            }

            if (!$response->successful()) {
                $detail = $body['message'] ?? $body['detail'] ?? 'Erro na validação';

                Log::warning('[SIMPAY][CPF] Resposta não-sucesso', [
                    'cpf_masked' => substr($cpf, 0, 3) . '***' . substr($cpf, -2),
                    'status' => $response->status(),
                    'detail' => $detail,
                ]);

                return [
                    'worked' => false,
                    'message' => $detail,
                ];
            }

            if (empty($body['worked'])) {
                return [
                    'worked' => false,
                    'message' => $body['message'] ?? 'CPF inválido ou não encontrado.',
                ];
            }

            Log::info('[SIMPAY][CPF] Validação bem-sucedida', [
                'cpf_masked' => substr($cpf, 0, 3) . '***' . substr($cpf, -2),
            ]);

            return [
                'worked' => true,
                'customer' => $body['customer'] ?? [],
            ];

        } catch (\Throwable $e) {
            Log::error('[SIMPAY][CPF] Erro ao validar CPF', [
                'cpf_masked' => substr($cpf, 0, 3) . '***' . substr($cpf, -2),
                'error' => $e->getMessage(),
            ]);

            return [
                'worked' => false,
                'message' => 'Erro ao consultar CPF. Tente novamente.',
            ];
        }
    }

    /**
     * Verifica se a resposta indica token expirado/inválido.
     */
    private function isTokenExpired($response, ?array $body): bool
    {
        if ($response->status() !== 401) {
            return false;
        }

        $code = $body['code'] ?? '';

        return $code === 'token_not_valid';
    }
}
