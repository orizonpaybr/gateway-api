<?php

namespace App\Services;

use App\Constants\AuthEventType;
use App\Models\AuthEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AuthAuditService
{
    public function log(
        string $eventType,
        Request $request,
        ?User $user = null,
        ?string $usernameAttempt = null,
        array $metadata = [],
    ): void {
        try {
            AuthEvent::create([
                'user_id' => $user?->id,
                'username_attempt' => $usernameAttempt,
                'event_type' => $eventType,
                'ip' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
                'metadata' => $metadata ?: null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::channel('security')->warning('Falha ao registrar auth_event', [
                'event_type' => $eventType,
                'error' => $e->getMessage(),
            ]);
        }

        Log::channel('security')->info('Auth event: '.$eventType, [
            'user_id' => $user?->id,
            'username_attempt' => $usernameAttempt,
            'ip' => $request->ip(),
            'metadata' => $metadata,
        ]);
    }

    public function countRecent(string $eventType, int $minutes): int
    {
        return AuthEvent::query()
            ->where('event_type', $eventType)
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->count();
    }

    public function countRecentByIp(string $eventType, string $ip, int $minutes): int
    {
        return AuthEvent::query()
            ->where('event_type', $eventType)
            ->where('ip', $ip)
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->count();
    }

    public function countRecentByEventTypes(array $eventTypes, int $minutes): int
    {
        return AuthEvent::query()
            ->whereIn('event_type', $eventTypes)
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->count();
    }

    public function topIpsByEventTypes(array $eventTypes, int $minutes, int $limit = 10): array
    {
        return AuthEvent::query()
            ->selectRaw('ip, COUNT(*) as total')
            ->whereIn('event_type', $eventTypes)
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->whereNotNull('ip')
            ->groupBy('ip')
            ->orderByDesc('total')
            ->limit($limit)
            ->pluck('total', 'ip')
            ->all();
    }

    public function topIpsByFailures(int $minutes, int $limit = 10): array
    {
        return $this->topIpsByEventTypes([
            AuthEventType::LOGIN_FAILED,
            AuthEventType::TWO_FA_FAILED,
        ], $minutes, $limit);
    }
}
