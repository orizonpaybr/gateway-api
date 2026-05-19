<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;

class PixKey extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'pix_keys';

    protected $fillable = [
        'user_id',
        'key_type',
        'key_value',
        'key_label',
        'is_active',
        'is_default',
        'verified_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'verified_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $hidden = [
        'deleted_at',
    ];

    /**
     * Relacionamento com User
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'username');
    }

    /**
     * Scope para chaves ativas
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope para buscar chaves de um usuário específico
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope para buscar por tipo de chave
     */
    public function scopeByType($query, $type)
    {
        return $query->where('key_type', $type);
    }

    /**
     * Obter chave padrão do usuário
     * ✅ OTIMIZADO: Adicionado limit(1) para performance
     */
    public static function getDefaultKey($userId)
    {
        $cacheKey = "pix_key:default:{$userId}";
        
        return Cache::remember($cacheKey, 300, function () use ($userId) {
            return self::forUser($userId)
                ->active()
                ->where('is_default', true)
                ->limit(1) // ✅ Performance: limita query a 1 resultado
                ->first();
        });
    }

    /**
     * Obter todas as chaves de um usuário com cache
     * ✅ OTIMIZADO: Select específico para performance
     */
    public static function getUserKeys($userId)
    {
        $cacheKey = "pix_keys:user:{$userId}";
        
        return Cache::remember($cacheKey, 300, function () use ($userId) {
            return self::select([
                    'id', 'user_id', 'key_type', 'key_value', 'key_label',
                    'is_active', 'is_default', 'verified_at', 'created_at', 'updated_at'
                ]) // ✅ Performance: seleciona apenas colunas necessárias
                ->forUser($userId)
                ->active()
                ->orderBy('is_default', 'desc')
                ->orderBy('created_at', 'desc')
                ->get();
        });
    }

    /**
     * ✅ NOVO: Prefetch de chaves ativas para melhor cache
     * Útil para pré-carregar dados em background
     */
    public static function prefetchUserKeys($userId)
    {
        // Força carregamento no cache sem retornar dados
        self::getUserKeys($userId);
        self::getDefaultKey($userId);
    }

    /**
     * Limpar cache do usuário
     */
    public function clearUserCache()
    {
        Cache::forget("pix_keys:user:{$this->user_id}");
        Cache::forget("pix_key:default:{$this->user_id}");
    }

    /**
     * Boot method para eventos
     */
    protected static function boot()
    {
        parent::boot();

        // Limpar cache após salvar
        static::saved(function ($pixKey) {
            $pixKey->clearUserCache();
        });

        // Limpar cache após deletar
        static::deleted(function ($pixKey) {
            $pixKey->clearUserCache();
        });

        // Se marcada como padrão, desmarcar outras
        static::saving(function ($pixKey) {
            if ($pixKey->is_default && $pixKey->isDirty('is_default')) {
                self::where('user_id', $pixKey->user_id)
                    ->where('id', '!=', $pixKey->id)
                    ->update(['is_default' => false]);
            }
        });
    }

    /**
     * Normaliza valor da chave para persistência / API do adquirente (por tipo).
     */
    public static function sanitizeValueForType(string $type, string $value): string
    {
        $type = strtolower(trim($type));

        return match ($type) {
            'cpf', 'cnpj', 'telefone' => preg_replace('/\D/', '', $value),
            'email' => strtolower(trim($value)),
            'aleatoria' => strtolower(trim($value)),
            default => trim($value),
        };
    }

    /**
     * Validar formato da chave conforme tipo
     */
    public static function validateKeyFormat($type, $value)
    {
        $type = strtolower((string) $type);
        $sanitized = self::sanitizeValueForType($type, (string) $value);

        return match ($type) {
            'cpf' => (bool) preg_match('/^\d{11}$/', $sanitized),
            'cnpj' => (bool) preg_match('/^\d{14}$/', $sanitized),
            'email' => filter_var($sanitized, FILTER_VALIDATE_EMAIL) !== false,
            'telefone' => (bool) preg_match('/^\d{10,11}$/', $sanitized),
            'aleatoria' => (bool) preg_match(
                '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i',
                $sanitized
            ) || (bool) preg_match('/^[a-f0-9]{32}$/i', $sanitized),
            default => false,
        };
    }

    /**
     * Formatar chave para exibição (mascarar dados sensíveis)
     */
    public function getFormattedKeyAttribute()
    {
        $value = $this->key_value;

        switch ($this->key_type) {
            case 'cpf':
                return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $value);
            
            case 'cnpj':
                return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $value);
            
            case 'telefone':
                if (strlen($value) === 11) {
                    return preg_replace('/(\d{2})(\d{5})(\d{4})/', '($1) $2-$3', $value);
                }
                return preg_replace('/(\d{2})(\d{4})(\d{4})/', '($1) $2-$3', $value);
            
            default:
                return $value;
        }
    }

    /**
     * Obter ícone do tipo de chave
     */
    public function getKeyIconAttribute()
    {
        $icons = [
            'cpf' => '👤',
            'cnpj' => '🏢',
            'telefone' => '📱',
            'email' => '✉️',
            'aleatoria' => '🔑'
        ];

        return $icons[$this->key_type] ?? '🔑';
    }

    /**
     * Obter label do tipo de chave
     */
    public function getKeyTypeLabel()
    {
        $labels = [
            'cpf' => 'CPF',
            'cnpj' => 'CNPJ',
            'telefone' => 'Telefone',
            'email' => 'E-mail',
            'aleatoria' => 'Aleatória'
        ];

        return $labels[$this->key_type] ?? $this->key_type;
    }
}

