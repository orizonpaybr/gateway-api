<?php

namespace App\Services\Treeal;

/**
 * Monta opções Guzzle/cURL para mTLS na API QR Treeal (CashIn).
 */
class TreealMtlsOptions
{
    /**
     * @return array<string, mixed>
     */
    public static function build(): array
    {
        $format = strtolower((string) config('treeal.cert_format', 'pfx'));
        $verify = self::normalizeVerify(config('treeal.verify_ssl', true));

        if ($format === 'pfx') {
            $pfx = self::resolvePath((string) config('treeal.cert_pfx_path'));
            $password = (string) config('treeal.cert_pfx_password');

            if ($pfx === null || $pfx === '') {
                throw new \RuntimeException(
                    'Certificado mTLS TREEAL QR: configure TREEAL_CERT_PFX_PATH (arquivo .pfx).'
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

        $pem = self::resolvePath((string) config('treeal.cert_pem_path'));
        $key = self::resolvePath((string) config('treeal.cert_key_path'));
        $keyPass = (string) config('treeal.cert_key_password');

        if ($pem === null || $pem === '' || $key === null || $key === '') {
            throw new \RuntimeException(
                'Certificado mTLS TREEAL QR: configure TREEAL_CERT_PEM_PATH e TREEAL_CERT_KEY_PATH.'
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
        $format = strtolower((string) config('treeal.cert_format', 'pfx'));

        if ($format === 'pfx') {
            return trim((string) config('treeal.cert_pfx_path')) !== '';
        }

        return trim((string) config('treeal.cert_pem_path')) !== ''
            && trim((string) config('treeal.cert_key_path')) !== '';
    }

    private static function resolvePath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, '/') || preg_match('#^[A-Za-z]:\\\\#', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }

    /**
     * @return bool|string
     */
    private static function normalizeVerify(mixed $value): bool|string
    {
        if (is_string($value)) {
            $trim = trim($value);
            $lower = strtolower($trim);
            if ($lower === 'false' || $lower === '0') {
                return false;
            }
            if ($lower === 'true' || $lower === '1') {
                return true;
            }
            if ($trim !== '') {
                return $trim;
            }
        }

        return (bool) $value;
    }
}
