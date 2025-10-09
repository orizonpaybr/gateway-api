<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PrimePay7 extends Model
{
    use HasFactory;

    protected $table = 'primepay7';

    protected $fillable = [
        'private_key',
        'public_key',
        'withdrawal_key',
        'url',
        'status',
        'taxa_adquirente_entradas',
        'taxa_adquirente_saidas'
    ];

    protected $casts = [
        'status' => 'boolean'
    ];

    /**
     * Retorna a URL da API (sempre produção)
     */
    public function getApiUrl()
    {
        return 'https://api.primepay7.com';
    }

    /**
     * Retorna a configuração ativa da PrimePay7
     */
    public static function getActiveConfig()
    {
        return self::where('status', true)->first();
    }
}
