<?php

namespace App\Services\TreealContas;

/**
 * Monta opções Guzzle/cURL para mTLS na API Contas Treeal (CashOut).
 */
class TreealContasMtlsOptions
{
    /**
     * @return array<string, mixed>
     */
    public static function build(): array
    {
        $format = strtolower((string) config('treeal_contas.cert_format', 'pfx'));
        $verify = (bool) config('treeal_contas.verify_ssl', true);

        if ($format === 'pfx') {
            $pfx = self::resolvePath((string) config('treeal_contas.cert_pfx_path'));
            $password = (string) config('treeal_contas.cert_pfx_password');

            if ($pfx === null || $pfx === '') {
                throw new \RuntimeException(
                    'Certificado mTLS TREEAL Contas: configure TREEAL_CONTAS_CERT_PFX_PATH (arquivo .pfx).'
                );
            }

            return [
                'verify' => $verify,
                'curl' => [
                    \CURLOPT_SSLCERTTYPE => 'P12',
                    \CURLOPT_SSLCERT => $pfx,
                    \CURLOPT_SSLCERTPASSWD => $password,
                ],
            ];
        }

        $pem = self::resolvePath((string) config('treeal_contas.cert_pem_path'));
        $key = self::resolvePath((string) config('treeal_contas.cert_key_path'));
        $keyPass = (string) config('treeal_contas.cert_key_password');

        if ($pem === null || $pem === '' || $key === null || $key === '') {
            throw new \RuntimeException(
                'Certificado mTLS TREEAL Contas: configure TREEAL_CONTAS_CERT_PEM_PATH e TREEAL_CONTAS_CERT_KEY_PATH.'
            );
        }

        return [
            'verify' => $verify,
            'cert' => $pem,
            'ssl_key' => $keyPass !== '' ? [$key, $keyPass] : $key,
        ];
    }

    public static function isConfigured(): bool
    {
        $format = strtolower((string) config('treeal_contas.cert_format', 'pfx'));

        if ($format === 'pfx') {
            return trim((string) config('treeal_contas.cert_pfx_path')) !== '';
        }

        return trim((string) config('treeal_contas.cert_pem_path')) !== ''
            && trim((string) config('treeal_contas.cert_key_path')) !== '';
    }

    private static function resolvePath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, '/') || preg_match('#^[A-Za-z]:\\\\#', $path) === 1) {
            return is_readable($path) ? $path : $path;
        }

        return base_path($path);
    }
}
