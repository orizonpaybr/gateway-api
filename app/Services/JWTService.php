<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use Firebase\JWT\BeforeValidException;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Serviço para gerenciamento de JWT (JSON Web Tokens)
 * 
 * Usa firebase/php-jwt para geração e validação de tokens
 * com assinatura criptográfica HS256
 */
class JWTService
{
    private string $secret;
    private string $algorithm = 'HS256';
    private int $expirationHours;
    private string $issuer;
    
    public function __construct()
    {
        $this->secret = config('jwt.secret');
        $this->algorithm = config('jwt.algorithm', 'HS256');
        $this->expirationHours = config('jwt.expiration_hours', 24);
        $this->issuer = config('jwt.issuer', config('app.name'));
        
        if (empty($this->secret) || $this->secret === 'SUA_CHAVE_JWT_AQUI') {
            throw new Exception('JWT_SECRET não está configurado. Execute: php artisan jwt:secret');
        }
    }
    
    /**
     * Gera um token JWT para o usuário
     * 
     * @param string $userId Username do usuário
     * @param array $additionalClaims Claims adicionais (opcional)
     * @param int|null $expirationHours Horas para expiração (opcional, usa config se não fornecido)
     * @return string Token JWT
     */
    public function generateToken(string $userId, array $additionalClaims = [], ?int $expirationHours = null): string
    {
        $expiration = $expirationHours ?? $this->expirationHours;
        $now = time();
        
        $payload = [
            'iss' => $this->issuer,           // Issuer
            'sub' => $userId,                  // Subject (user identifier)
            'iat' => $now,                     // Issued at
            'exp' => $now + ($expiration * 3600), // Expiration
            'nbf' => $now,                     // Not before
            'jti' => bin2hex(random_bytes(16)), // JWT ID (unique identifier)
        ];
        
        // Adicionar claims extras (sem dados sensíveis!)
        foreach ($additionalClaims as $key => $value) {
            // Não permitir sobrescrever claims padrão
            if (!in_array($key, ['iss', 'sub', 'iat', 'exp', 'nbf', 'jti'])) {
                $payload[$key] = $value;
            }
        }
        
        try {
            $token = JWT::encode($payload, $this->secret, $this->algorithm);
            
            Log::debug('JWTService::generateToken - Token gerado', [
                'user_id' => $userId,
                'expires_at' => date('Y-m-d H:i:s', $payload['exp']),
                'jti' => $payload['jti'],
            ]);
            
            return $token;
            
        } catch (Exception $e) {
            Log::error('JWTService::generateToken - Erro ao gerar token', [
                'error' => $e->getMessage(),
                'user_id' => $userId,
            ]);
            throw $e;
        }
    }
    
    /**
     * Gera um token temporário para 2FA (curta duração)
     * 
     * @param string $userId Username do usuário
     * @return string Token JWT temporário
     */
    public function generateTempToken(string $userId): string
    {
        return $this->generateToken($userId, [
            'temp' => true,
            'purpose' => '2fa_verification',
        ], 0); // 0 horas = usa apenas minutos
    }
    
    /**
     * Gera um token temporário para 2FA com 5 minutos de validade
     * 
     * @param string $userId Username do usuário
     * @return string Token JWT temporário
     */
    public function generate2FAToken(string $userId): string
    {
        $now = time();
        
        $payload = [
            'iss' => $this->issuer,
            'sub' => $userId,
            'iat' => $now,
            'exp' => $now + (5 * 60), // 5 minutos
            'nbf' => $now,
            'jti' => bin2hex(random_bytes(16)),
            'temp' => true,
            'purpose' => '2fa_verification',
        ];
        
        return JWT::encode($payload, $this->secret, $this->algorithm);
    }
    
    /**
     * Valida e decodifica um token JWT
     * 
     * @param string $token Token JWT
     * @return object|null Payload decodificado ou null se inválido
     */
    public function validateToken(string $token): ?object
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret, $this->algorithm));
            
            Log::debug('JWTService::validateToken - Token válido', [
                'user_id' => $decoded->sub ?? 'unknown',
                'expires_at' => isset($decoded->exp) ? date('Y-m-d H:i:s', $decoded->exp) : 'unknown',
            ]);
            
            return $decoded;
            
        } catch (ExpiredException $e) {
            Log::warning('JWTService::validateToken - Token expirado', [
                'error' => $e->getMessage(),
            ]);
            return null;
            
        } catch (SignatureInvalidException $e) {
            Log::warning('JWTService::validateToken - Assinatura inválida', [
                'error' => $e->getMessage(),
            ]);
            return null;
            
        } catch (BeforeValidException $e) {
            Log::warning('JWTService::validateToken - Token não válido ainda (nbf)', [
                'error' => $e->getMessage(),
            ]);
            return null;
            
        } catch (Exception $e) {
            Log::warning('JWTService::validateToken - Erro ao validar token', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
    
    /**
     * Extrai o user_id (subject) de um token válido
     * 
     * @param string $token Token JWT
     * @return string|null User ID ou null se inválido
     */
    public function getUserId(string $token): ?string
    {
        $decoded = $this->validateToken($token);
        
        if ($decoded && isset($decoded->sub)) {
            return $decoded->sub;
        }
        
        return null;
    }
    
    /**
     * Verifica se um token é temporário (para 2FA)
     * 
     * @param string $token Token JWT
     * @return bool
     */
    public function isTempToken(string $token): bool
    {
        $decoded = $this->validateToken($token);
        
        return $decoded && isset($decoded->temp) && $decoded->temp === true;
    }
    
    /**
     * Obtém o tempo restante de validade do token em segundos
     * 
     * @param string $token Token JWT
     * @return int Segundos restantes (0 se expirado ou inválido)
     */
    public function getTimeToExpiration(string $token): int
    {
        $decoded = $this->validateToken($token);
        
        if ($decoded && isset($decoded->exp)) {
            $remaining = $decoded->exp - time();
            return max(0, $remaining);
        }
        
        return 0;
    }
    
    /**
     * Refresh de token - gera novo token com mesmos claims (exceto exp, iat, jti)
     * 
     * Importante: Este método não invalida o token antigo. Para blacklist,
     * seria necessário implementar armazenamento em cache/banco.
     * 
     * @param string $token Token JWT atual
     * @return string|null Novo token ou null se token inválido
     */
    public function refreshToken(string $token): ?string
    {
        $decoded = $this->validateToken($token);
        
        if (!$decoded || !isset($decoded->sub)) {
            return null;
        }
        
        // Não permitir refresh de tokens temporários
        if (isset($decoded->temp) && $decoded->temp === true) {
            Log::warning('JWTService::refreshToken - Tentativa de refresh de token temporário');
            return null;
        }
        
        // Extrair claims personalizados (exceto padrões)
        $additionalClaims = [];
        $standardClaims = ['iss', 'sub', 'iat', 'exp', 'nbf', 'jti'];
        
        foreach ((array) $decoded as $key => $value) {
            if (!in_array($key, $standardClaims)) {
                $additionalClaims[$key] = $value;
            }
        }
        
        return $this->generateToken($decoded->sub, $additionalClaims);
    }
}
