<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Log;

/**
 * Model para chaves de API dos usuários
 * 
 * IMPORTANTE: Os campos 'token' e 'secret' são automaticamente
 * criptografados ao salvar e descriptografados ao ler.
 * 
 * O campo 'token_lookup' armazena um hash SHA256 do token para busca eficiente.
 * 
 * Isso protege os dados sensíveis em caso de vazamento do banco de dados.
 */
class UsersKey extends Model
{
    use HasFactory;
    
    protected $table = "users_key";

    protected $fillable = [
        "user_id",
        "token",
        "secret",
        "status",
        "token_lookup",
    ];

    /**
     * Campos que devem ser ocultados em arrays/JSON
     */
    protected $hidden = [
        'secret',
        'token_lookup',
    ];

    /**
     * Accessor para descriptografar o token ao ler
     */
    public function getTokenAttribute($value)
    {
        if (empty($value)) {
            return $value;
        }

        // Tentar descriptografar - se falhar, pode ser um valor antigo não criptografado
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException $e) {
            // Retornar valor original se não estiver criptografado (compatibilidade)
            return $value;
        }
    }

    /**
     * Mutator para criptografar o token ao salvar
     */
    public function setTokenAttribute($value)
    {
        if (empty($value)) {
            $this->attributes['token'] = $value;
            $this->attributes['token_lookup'] = null;
            return;
        }

        // Gerar hash para lookup (antes de criptografar)
        $plainToken = $value;
        
        // Verificar se já está criptografado
        try {
            $plainToken = Crypt::decryptString($value);
            // Se conseguiu descriptografar, já está criptografado
            $this->attributes['token'] = $value;
        } catch (DecryptException $e) {
            // Não está criptografado, criptografar agora
            $this->attributes['token'] = Crypt::encryptString($value);
        }
        
        // Sempre atualizar o token_lookup com hash do valor plain
        $this->attributes['token_lookup'] = hash('sha256', $plainToken);
    }

    /**
     * Accessor para descriptografar o secret ao ler
     */
    public function getSecretAttribute($value)
    {
        if (empty($value)) {
            return $value;
        }

        // Tentar descriptografar - se falhar, pode ser um valor antigo não criptografado
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException $e) {
            // Retornar valor original se não estiver criptografado (compatibilidade)
            return $value;
        }
    }

    /**
     * Mutator para criptografar o secret ao salvar
     */
    public function setSecretAttribute($value)
    {
        if (empty($value)) {
            $this->attributes['secret'] = $value;
            return;
        }

        // Verificar se já está criptografado
        try {
            Crypt::decryptString($value);
            // Se conseguiu descriptografar, já está criptografado
            $this->attributes['secret'] = $value;
        } catch (DecryptException $e) {
            // Não está criptografado, criptografar agora
            $this->attributes['secret'] = Crypt::encryptString($value);
        }
    }

    /**
     * Busca chave por token usando o lookup hash
     * 
     * @param string $token Token em texto plano
     * @return UsersKey|null
     */
    public static function findByToken(string $token): ?self
    {
        $tokenHash = hash('sha256', $token);
        
        // Primeiro, tentar buscar pelo hash (mais eficiente)
        $key = self::where('token_lookup', $tokenHash)->first();
        
        if ($key) {
            return $key;
        }
        
        // Fallback: buscar por token direto (para registros não migrados)
        // Isso é necessário durante a transição
        $key = self::where('token', $token)->first();
        
        return $key;
    }

    /**
     * Busca chave por token e secret
     * 
     * @param string $token Token em texto plano
     * @param string $secret Secret em texto plano
     * @return UsersKey|null
     */
    public static function findByCredentials(string $token, string $secret): ?self
    {
        $key = self::findByToken($token);
        
        if (!$key) {
            return null;
        }
        
        // Verificar se o secret corresponde
        // O accessor vai descriptografar automaticamente
        if ($key->secret === $secret) {
            return $key;
        }
        
        return null;
    }

    /**
     * Verifica se as credenciais (token/secret) estão criptografadas
     */
    public function areCredentialsEncrypted(): bool
    {
        try {
            // Acessar os atributos raw (sem accessor)
            $rawToken = $this->attributes['token'] ?? null;
            $rawSecret = $this->attributes['secret'] ?? null;
            
            if (empty($rawToken) || empty($rawSecret)) {
                return false;
            }
            
            // Tentar descriptografar
            Crypt::decryptString($rawToken);
            Crypt::decryptString($rawSecret);
            
            return true;
        } catch (DecryptException $e) {
            return false;
        }
    }

    /**
     * Força a criptografia das credenciais (útil para migração)
     */
    public function encryptCredentials(): bool
    {
        if ($this->areCredentialsEncrypted()) {
            // Verificar se token_lookup existe
            if (!empty($this->attributes['token_lookup'])) {
                return true; // Já está completamente criptografado
            }
        }
        
        try {
            // Pegar valores - se estiver criptografado, o accessor descriptografa
            $plainToken = $this->token;
            $plainSecret = $this->secret;
            
            // Forçar a criptografia e atualização do lookup
            $this->attributes['token'] = Crypt::encryptString($plainToken);
            $this->attributes['secret'] = Crypt::encryptString($plainSecret);
            $this->attributes['token_lookup'] = hash('sha256', $plainToken);
            
            return $this->save();
        } catch (\Exception $e) {
            Log::error('UsersKey::encryptCredentials - Erro ao criptografar', [
                'user_id' => $this->user_id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'username');
    }
}
