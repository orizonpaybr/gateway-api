<?php

namespace App\Services\Fyhub;

/**
 * Monta opções Guzzle/cURL para mTLS na API QRCode Fyhub (cash-in).
 *
 * A API QRCode exige envio de certificado mTLS em todas as requisições,
 * inclusive no /oauth/token. Suporta PFX (PKCS#12) ou par PEM + KEY.
 */
class FyhubMtlsOptions
{
    /**
     * @return array<string, mixed>
     */
    public static function build(): array
    {
        $format = strtolower((string) config('fyhub.cert_format', 'pfx'));
        $verify = self::normalizeVerify(config('fyhub.verify_ssl', true));

        if ($format === 'pfx') {
            $pfx = self::resolvePath((string) config('fyhub.cert_pfx_path'));
            $password = (string) config('fyhub.cert_pfx_password');

            if ($pfx === null || $pfx === '') {
                throw new \RuntimeException(
                    'Certificado mTLS FYHUB QR: configure FYHUB_CERT_PFX_PATH (arquivo .pfx).'
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

        $pem = self::resolvePath((string) config('fyhub.cert_pem_path'));
        $key = self::resolvePath((string) config('fyhub.cert_key_path'));
        $keyPass = (string) config('fyhub.cert_key_password');

        if ($pem === null || $pem === '' || $key === null || $key === '') {
            throw new \RuntimeException(
                'Certificado mTLS FYHUB QR: configure FYHUB_CERT_PEM_PATH e FYHUB_CERT_KEY_PATH.'
            );
        }

        return [
            'verify' => $verify,
            'cert' => $pem,
            'ssl_key' => $keyPass !== '' ? [$key, $keyPass] : $key,
        ];
    }

    /**
     * Indica se o cliente tem certificado configurado (sem disparar exceção).
     */
    public static function isConfigured(): bool
    {
        $format = strtolower((string) config('fyhub.cert_format', 'pfx'));

        if ($format === 'pfx') {
            return trim((string) config('fyhub.cert_pfx_path')) !== '';
        }

        return trim((string) config('fyhub.cert_pem_path')) !== ''
            && trim((string) config('fyhub.cert_key_path')) !== '';
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
     * Aceita bool ou caminho para CA bundle (igual ao Guzzle).
     *
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
