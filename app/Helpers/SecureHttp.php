<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;

class SecureHttp
{
    /**
     * Timeout padrão para requisições (30 segundos)
     */
    private static int $defaultTimeout = 30;

    /**
     * Número máximo de tentativas para requisições
     */
    private static int $maxRetries = 3;

    /**
     * Delay entre tentativas (em segundos)
     */
    private static int $retryDelay = 2;

    /**
     * Faz uma requisição POST com configurações seguras
     */
    public static function post(string $url, array $data = [], array $headers = [], int $timeout = null): Response
    {
        return self::makeRequest('POST', $url, $data, $headers, $timeout);
    }

    /**
     * Faz uma requisição GET com configurações seguras
     */
    public static function get(string $url, array $headers = [], int $timeout = null): Response
    {
        return self::makeRequest('GET', $url, [], $headers, $timeout);
    }

    /**
     * Faz uma requisição PUT com configurações seguras
     */
    public static function put(string $url, array $data = [], array $headers = [], int $timeout = null): Response
    {
        return self::makeRequest('PUT', $url, $data, $headers, $timeout);
    }

    /**
     * Faz uma requisição DELETE com configurações seguras
     */
    public static function delete(string $url, array $data = [], array $headers = [], int $timeout = null): Response
    {
        return self::makeRequest('DELETE', $url, $data, $headers, $timeout);
    }

    /**
     * Executa uma requisição com retry automático
     */
    public static function makeRequest(string $method, string $url, array $data = [], array $headers = [], int $timeout = null): Response
    {
        $timeout = $timeout ?? self::$defaultTimeout;
        $attempt = 1;

        while ($attempt <= self::$maxRetries) {
            try {
                $response = Http::timeout($timeout)
                    ->withHeaders(array_merge([
                        'User-Agent' => 'PlayGameGateway/1.0',
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ], $headers))
                    ->{strtolower($method)}($url, $data);

                // Se a requisição foi bem-sucedida, retorna
                if ($response->successful() || $response->status() < 500) {
                    return $response;
                }

                // Se não foi bem-sucedida e ainda temos tentativas, tenta novamente
                if ($attempt < self::$maxRetries) {
                    SecureLog::warning("Tentativa {$attempt} falhou, tentando novamente", [
                        'url' => $url,
                        'method' => $method,
                        'status' => $response->status(),
                        'attempt' => $attempt
                    ]);
                    
                    sleep(self::$retryDelay * $attempt); // Delay progressivo
                    $attempt++;
                    continue;
                }

                return $response;

            } catch (\Exception $e) {
                SecureLog::error("Erro na requisição HTTP (tentativa {$attempt})", [
                    'url' => $url,
                    'method' => $method,
                    'error' => $e->getMessage(),
                    'attempt' => $attempt
                ]);

                if ($attempt >= self::$maxRetries) {
                    throw $e;
                }

                sleep(self::$retryDelay * $attempt);
                $attempt++;
            }
        }

        // Fallback - nunca deveria chegar aqui
        return Http::timeout($timeout)->{strtolower($method)}($url, $data);
    }

    /**
     * Faz uma requisição com certificado SSL
     */
    public static function postWithCert(string $url, array $data = [], array $headers = [], string $certPath = null, string $certPassword = ''): Response
    {
        $timeout = self::$defaultTimeout;
        
        return Http::timeout($timeout)
            ->withOptions([
                'cert' => $certPath ? [$certPath, $certPassword] : null,
                'verify' => true,
            ])
            ->withHeaders(array_merge([
                'User-Agent' => 'PlayGameGateway/1.0',
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ], $headers))
            ->post($url, $data);
    }

    /**
     * Configura timeout padrão
     */
    public static function setDefaultTimeout(int $timeout): void
    {
        self::$defaultTimeout = $timeout;
    }

    /**
     * Configura número máximo de tentativas
     */
    public static function setMaxRetries(int $retries): void
    {
        self::$maxRetries = $retries;
    }

    /**
     * Configura delay entre tentativas
     */
    public static function setRetryDelay(int $delay): void
    {
        self::$retryDelay = $delay;
    }
}
