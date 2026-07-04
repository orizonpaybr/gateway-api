<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class IpReputationService
{
    public function isEnabled(): bool
    {
        return (bool) config('auth_security.ip_reputation_enabled', false)
            && ! empty(config('auth_security.abuseipdb_api_key'));
    }

    public function isSuspicious(string $ip): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $cacheKey = 'ip-reputation:'.$ip;
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return (bool) $cached;
        }

        $suspicious = $this->checkAbuseIpDb($ip);
        $ttl = max(300, (int) config('auth_security.ip_reputation_cache_ttl', 86400));
        Cache::put($cacheKey, $suspicious, $ttl);

        return $suspicious;
    }

    private function checkAbuseIpDb(string $ip): bool
    {
        try {
            $response = Http::withHeaders([
                'Key' => config('auth_security.abuseipdb_api_key'),
                'Accept' => 'application/json',
            ])->get('https://api.abuseipdb.com/api/v2/check', [
                'ipAddress' => $ip,
                'maxAgeInDays' => 90,
            ]);

            if (! $response->successful()) {
                Log::channel('security')->warning('AbuseIPDB check failed', [
                    'ip' => $ip,
                    'status' => $response->status(),
                ]);

                return false;
            }

            $score = (int) ($response->json('data.abuseConfidenceScore') ?? 0);
            $maxScore = (int) config('auth_security.abuseipdb_max_score', 75);

            return $score >= $maxScore;
        } catch (\Throwable $e) {
            Log::channel('security')->error('AbuseIPDB error', [
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
