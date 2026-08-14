<?php

namespace App\Services\FyhubContas;

/**
 * Monta opções Guzzle/cURL para mTLS na API Contas Fyhub.
 */
class FyhubContasMtlsOptions
{
    /**
     * @return array<string, mixed>
     */
    public static function build(): array
    {
        $format = strtolower((string) config('fyhub_contas.cert_format', 'pfx'));
        $verify = (bool) config('fyhub_contas.verify_ssl', true);

        if ($format === 'pfx') {
            $pfx = self::resolvePath((string) config('fyhub_contas.cert_pfx_path'));
            $password = (string) config('fyhub_contas.cert_pfx_password');

            if ($pfx === null || $pfx === '') {
                throw new \RuntimeException(
                    'Certificado mTLS FYHUB Contas: configure FYHUB_CONTAS_CERT_PFX_PATH (arquivo .pfx).'
                );
            }

            self::assertReadable($pfx, 'FYHUB_CONTAS_CERT_PFX_PATH');

            return [
                'verify' => $verify,
                'curl' => [
                    \CURLOPT_SSLCERTTYPE => 'P12',
                    \CURLOPT_SSLCERT => $pfx,
                    \CURLOPT_SSLCERTPASSWD => $password,
                ],
            ];
        }

        $pem = self::resolvePath((string) config('fyhub_contas.cert_pem_path'));
        $key = self::resolvePath((string) config('fyhub_contas.cert_key_path'));
        $keyPass = (string) config('fyhub_contas.cert_key_password');

        if ($pem === null || $pem === '' || $key === null || $key === '') {
            throw new \RuntimeException(
                'Certificado mTLS FYHUB Contas: configure FYHUB_CONTAS_CERT_PEM_PATH e FYHUB_CONTAS_CERT_KEY_PATH.'
            );
        }

        self::assertReadable($pem, 'FYHUB_CONTAS_CERT_PEM_PATH');
        self::assertReadable($key, 'FYHUB_CONTAS_CERT_KEY_PATH');

        $opts = [
            'verify' => $verify,
            'cert' => $pem,
            'ssl_key' => $keyPass !== '' ? [$key, $keyPass] : $key,
        ];

        return $opts;
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

    private static function assertReadable(string $path, string $envKey): void
    {
        if (! is_readable($path)) {
            throw new \RuntimeException(
                "Certificado mTLS FYHUB Contas ({$envKey}) não encontrado ou sem permissão de leitura: {$path}"
            );
        }
    }
}
