<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Revoga JWTs via cache. Em produção com múltiplos workers, use CACHE_STORE=redis.
 */
class JwtBlacklistService
{
    public function isEnabled(): bool
    {
        return (bool) config('auth_security.jwt_blacklist_enabled', true);
    }

    public function blacklist(string $jti, int $ttlSeconds): void
    {
        if (! $this->isEnabled() || $ttlSeconds <= 0) {
            return;
        }

        Cache::put($this->key($jti), true, $ttlSeconds);
    }

    public function isBlacklisted(?string $jti): bool
    {
        if (! $this->isEnabled() || empty($jti)) {
            return false;
        }

        return Cache::has($this->key($jti));
    }

    public function blacklistFromToken(object $decoded): void
    {
        if (! isset($decoded->jti, $decoded->exp)) {
            return;
        }

        $remaining = max(1, (int) $decoded->exp - time());
        $this->blacklist($decoded->jti, $remaining);
    }

    private function key(string $jti): string
    {
        return 'jwt-blacklist:'.$jti;
    }
}
