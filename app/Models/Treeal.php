<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Treeal extends Model
{
    protected $table = 'treeal';

    protected $fillable = [
        'environment',
        'qrcodes_api_url',
        'accounts_api_url',
        'certificate_path',
        'certificate_password',
        'client_id', // Para Accounts API
        'client_secret', // Para Accounts API
        'qrcodes_client_id', // Para QR Codes API
        'qrcodes_client_secret', // Para QR Codes API
        'pix_key_secondary',
        'taxa_pix_cash_in',
        'taxa_pix_cash_out',
        'webhook_secret',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
        'taxa_pix_cash_in' => 'decimal:2',
        'taxa_pix_cash_out' => 'decimal:2',
    ];

    /**
     * Verifica se está configurado
     * Exige certificado(s): ou o único (sandbox) ou ambos Cash In + Cash Out (produção).
     */
    public function isConfigured(): bool
    {
        $singlePath = config('treeal.certificate_path');
        $singlePass = config('treeal.certificate_password');
        $qrcodesPath = config('treeal.qrcodes_certificate_path') ?: $singlePath;
        $qrcodesPass = config('treeal.qrcodes_certificate_password') ?: $singlePass;
        $accountsPath = config('treeal.accounts_certificate_path') ?: $singlePath;
        $accountsPass = config('treeal.accounts_certificate_password') ?: $singlePass;
        return (!empty($qrcodesPath) && !empty($qrcodesPass)) && (!empty($accountsPath) && !empty($accountsPass));
    }

    /**
     * Verifica se está ativo
     */
    public function isActive(): bool
    {
        return $this->status && $this->isConfigured();
    }

    /**
     * Retorna o caminho completo do certificado (fallback único)
     */
    public function getCertificateFullPath(): ?string
    {
        return $this->resolveCertificatePath(config('treeal.certificate_path'));
    }

    /** Certificado para QR Codes API (Cash In) – pasta QRCODES-MTLS. */
    public function getQrcodesCertificateFullPath(): ?string
    {
        $path = config('treeal.qrcodes_certificate_path') ?: config('treeal.certificate_path');
        return $this->resolveCertificatePath($path);
    }

    /** Certificado para Accounts API (Cash Out) – pasta ACCOUNTS. */
    public function getAccountsCertificateFullPath(): ?string
    {
        $path = config('treeal.accounts_certificate_path') ?: config('treeal.certificate_path');
        return $this->resolveCertificatePath($path);
    }

    private function resolveCertificatePath(?string $certPath): ?string
    {
        if (!$certPath) {
            return null;
        }
        if (str_starts_with($certPath, '/')) {
            return $certPath;
        }
        return storage_path('app/certificates/' . $certPath);
    }
}