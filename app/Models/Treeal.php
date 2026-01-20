<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Treeal extends Model
{
    protected $table = 'treeal'; // Adicione esta linha
    
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
     * Agora lê apenas do .env (colunas removidas do banco)
     */
    public function isConfigured(): bool
    {
        $certPath = config('treeal.certificate_path');
        $certPassword = config('treeal.certificate_password');
        
        return !empty($certPath) && !empty($certPassword);
    }

    /**
     * Verifica se está ativo
     */
    public function isActive(): bool
    {
        return $this->status && $this->isConfigured();
    }

    /**
     * Retorna o caminho completo do certificado
     * 
     * Agora lê apenas do .env (coluna removida do banco)
     */
    public function getCertificateFullPath(): ?string
    {
        $certPath = config('treeal.certificate_path');
        
        if (!$certPath) {
            return null;
        }

        // Se já é um caminho absoluto, retornar direto
        if (str_starts_with($certPath, '/')) {
            return $certPath;
        }

        // Caso contrário, assumir que está em storage/app/certificates
        return storage_path('app/certificates/' . $certPath);
    }
}