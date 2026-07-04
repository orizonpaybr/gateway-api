<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TurnstileVerificationService
{
    public function isConfigured(): bool
    {
        return ! empty(config('auth_security.turnstile_secret_key'));
    }

    public function verify(?string $token, ?string $remoteIp = null): bool
    {
        if (! $this->isConfigured()) {
            return true;
        }

        if (empty($token)) {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout(10)
                ->post(config('auth_security.turnstile_verify_url'), [
                    'secret' => config('auth_security.turnstile_secret_key'),
                    'response' => $token,
                    'remoteip' => $remoteIp,
                ]);

            if (! $response->successful()) {
                Log::channel('security')->warning('Turnstile API error', [
                    'status' => $response->status(),
                ]);

                return (bool) config('auth_security.turnstile_fail_open', false);
            }

            return (bool) $response->json('success', false);
        } catch (\Throwable $e) {
            Log::channel('security')->error('Turnstile verification failed', [
                'error' => $e->getMessage(),
            ]);

            return (bool) config('auth_security.turnstile_fail_open', false);
        }
    }
}
