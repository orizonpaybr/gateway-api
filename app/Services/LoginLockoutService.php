<?php

namespace App\Services;

use App\Constants\AuthEventType;
use App\Models\User;
use App\Services\JwtBlacklistService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class LoginLockoutService
{
    public function __construct(
        private AuthAuditService $audit,
    ) {}

    /**
     * @return array<int, array{attempts: int, minutes: int}>
     */
    public function tiers(): array
    {
        $tiers = config('auth_security.lockout_tiers', [
            ['attempts' => 3, 'minutes' => 15],
            ['attempts' => 2, 'minutes' => 30],
            ['attempts' => 1, 'minutes' => 1440],
        ]);

        return array_values(array_map(static fn (array $tier): array => [
            'attempts' => max(1, (int) ($tier['attempts'] ?? 1)),
            'minutes' => max(1, (int) ($tier['minutes'] ?? 15)),
        ], $tiers));
    }

    public function isLocked(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        $this->refreshLockStateIfExpired($user);

        return $user->locked_until && $user->locked_until->isFuture();
    }

    public function lockedUntil(?User $user): ?Carbon
    {
        if (! $user) {
            return null;
        }

        $this->refreshLockStateIfExpired($user);

        if (! $user->locked_until || $user->locked_until->isPast()) {
            return null;
        }

        return $user->locked_until;
    }

    public function retryAfterSeconds(?User $user): int
    {
        $lockedUntil = $this->lockedUntil($user);

        if (! $lockedUntil) {
            return 0;
        }

        return max(1, (int) now()->diffInSeconds($lockedUntil));
    }

    /**
     * @return array{status: int, payload: array<string, mixed>}
     */
    public function lockedJsonResponse(User $user, string $message): array
    {
        $retryAfter = $this->retryAfterSeconds($user);

        return [
            'status' => 429,
            'payload' => [
                'success' => false,
                'message' => $message,
                'retry_after' => $retryAfter,
            ],
        ];
    }

    /**
     * @return array{locked: bool, banned: bool, retry_after: int}
     */
    public function recordFailedAttempt(Request $request, ?User $user, string $username): array
    {
        $this->hitIpLimiter($request);

        $result = ['locked' => false, 'banned' => false, 'retry_after' => 0];

        if (! $user) {
            $this->audit->log(AuthEventType::LOGIN_FAILED, $request, null, $username);

            return $result;
        }

        $this->refreshLockStateIfExpired($user);

        if ($user->banido) {
            $this->audit->log(AuthEventType::LOGIN_FAILED, $request, $user, $username);

            return ['locked' => false, 'banned' => true, 'retry_after' => 0];
        }

        $user->failed_login_attempts = ($user->failed_login_attempts ?? 0) + 1;
        $maxAttempts = $this->maxAttemptsForTier($user);

        if ($user->failed_login_attempts >= $maxAttempts) {
            if ($this->shouldPermanentlyBan($user)) {
                $this->applyPermanentBan($request, $user, $username);
                $result['banned'] = true;
            } else {
                $this->applyTierLock($request, $user, $username);
                $result['locked'] = true;
                $result['retry_after'] = $this->retryAfterSeconds($user);
            }
        }

        $user->save();
        $this->audit->log(AuthEventType::LOGIN_FAILED, $request, $user, $username);

        return $result;
    }

    /**
     * @return array{temp_failures: int, session_terminated: bool, account_locked: bool, banned: bool, retry_after: int}
     */
    public function recordTwoFaFailedAttempt(
        Request $request,
        User $user,
        object $decodedTempToken,
        JwtBlacklistService $jwtBlacklist,
    ): array {
        $this->hitIpLimiter($request);

        $this->refreshLockStateIfExpired($user);

        $accountLocked = false;
        $banned = false;
        $retryAfter = 0;

        if (! $user->banido) {
            $user->failed_login_attempts = ($user->failed_login_attempts ?? 0) + 1;
            $maxAttempts = $this->maxAttemptsForTier($user);

            if ($user->failed_login_attempts >= $maxAttempts) {
                if ($this->shouldPermanentlyBan($user)) {
                    $this->applyPermanentBan($request, $user, $user->username);
                    $banned = true;
                } else {
                    $this->applyTierLock($request, $user, $user->username, 'verify-2fa');
                    $accountLocked = true;
                    $retryAfter = $this->retryAfterSeconds($user);
                }
            }

            $user->save();
        }

        $tempKey = $this->tempTokenKey($decodedTempToken);
        $decay = max(60, (int) config('auth_security.decay_seconds', 900));
        RateLimiter::hit($tempKey, $decay);
        $tempFailures = (int) RateLimiter::attempts($tempKey);

        $maxTemp = max(1, (int) config('auth_security.max_2fa_attempts_per_temp_token', 5));
        $sessionTerminated = false;

        if ($tempFailures >= $maxTemp || $accountLocked || $banned) {
            $jwtBlacklist->blacklistFromToken($decodedTempToken);
            $sessionTerminated = true;
            $this->audit->log(AuthEventType::TWO_FA_SESSION_TERMINATED, $request, $user, $user->username, [
                'temp_failures' => $tempFailures,
                'account_locked' => $accountLocked,
                'banned' => $banned,
            ]);
        }

        $this->audit->log(AuthEventType::TWO_FA_FAILED, $request, $user, $user->username, [
            'temp_failures' => $tempFailures,
        ]);

        return [
            'temp_failures' => $tempFailures,
            'session_terminated' => $sessionTerminated,
            'account_locked' => $accountLocked,
            'banned' => $banned,
            'retry_after' => $retryAfter,
        ];
    }

    public function requiresTwoFaCaptcha(Request $request): bool
    {
        $threshold = max(1, (int) config('auth_security.2fa_captcha_after_failures', 3));

        return $this->failureCountForIp($request) >= $threshold;
    }

    /**
     * @return array{status: int, payload: array<string, mixed>}|null
     */
    public function checkTwoFaAllowed(Request $request, User $user): ?array
    {
        $this->refreshLockStateIfExpired($user);

        if ($user->banido) {
            return [
                'status' => 403,
                'payload' => [
                    'success' => false,
                    'message' => 'Conta bloqueada permanentemente por excesso de tentativas. Entre em contato com o suporte.',
                    'session_terminated' => true,
                    'account_banned' => true,
                ],
            ];
        }

        if ($this->isLocked($user)) {
            $response = $this->lockedJsonResponse(
                $user,
                'Conta temporariamente bloqueada por excesso de tentativas. Faça login novamente.',
            );
            $response['payload']['session_terminated'] = true;

            return $response;
        }

        if ($this->ipTooManyAttempts($request)) {
            $retryAfter = $this->ipRetryAfter($request);

            return [
                'status' => 429,
                'payload' => [
                    'success' => false,
                    'message' => 'Muitas tentativas deste IP. Tente novamente mais tarde.',
                    'retry_after' => $retryAfter,
                ],
            ];
        }

        return null;
    }

    public function resolveUsernameFromRequest(Request $request, ?object $decodedTempToken = null): string
    {
        $username = (string) $request->input('username', '');
        if ($username !== '') {
            return $username;
        }

        if ($decodedTempToken && isset($decodedTempToken->sub)) {
            return (string) $decodedTempToken->sub;
        }

        return '';
    }

    public function clearFailedAttempts(User $user): void
    {
        $this->resetLockoutState($user);
        $user->save();
        RateLimiter::clear($this->accountKey($user->username));
    }

    public function unlock(User $user): void
    {
        $this->resetLockoutState($user);
        $user->save();
        RateLimiter::clear($this->accountKey($user->username));
    }

    public function unlockByAdmin(User $user, ?Request $request = null): void
    {
        $this->resetLockoutState($user);
        $user->save();
        RateLimiter::clear($this->accountKey($user->username));

        if ($request) {
            $this->clearIpLimiter($request);
        }
    }

    public function ipTooManyAttempts(Request $request): bool
    {
        return RateLimiter::tooManyAttempts(
            $this->ipKey($request),
            max(1, (int) config('auth_security.max_attempts_per_ip', 10)),
        );
    }

    public function accountTooManyAttempts(string $username): bool
    {
        $user = User::query()
            ->where('username', $username)
            ->orWhere('email', $username)
            ->first();

        if ($user) {
            $this->refreshLockStateIfExpired($user);

            return (bool) $user->banido || $this->isLocked($user);
        }

        return RateLimiter::tooManyAttempts(
            $this->accountKey($username),
            max(1, (int) config('auth_security.max_attempts_per_account', 5)),
        );
    }

    public function ipRetryAfter(Request $request): int
    {
        return RateLimiter::availableIn($this->ipKey($request));
    }

    public function accountRetryAfter(string $username): int
    {
        $user = User::query()
            ->where('username', $username)
            ->orWhere('email', $username)
            ->first();

        if ($user) {
            return $this->retryAfterSeconds($user);
        }

        return RateLimiter::availableIn($this->accountKey($username));
    }

    public function failureCountForIp(Request $request): int
    {
        return (int) RateLimiter::attempts($this->ipKey($request));
    }

    public function requiresCaptcha(Request $request): bool
    {
        $threshold = max(1, (int) config('auth_security.captcha_after_failures', 3));

        return $this->failureCountForIp($request) >= $threshold;
    }

    public function clearIpLimiter(Request $request): void
    {
        RateLimiter::clear($this->ipKey($request));
    }

    public function refreshLockStateIfExpired(User $user): void
    {
        if (! $user->locked_until || $user->locked_until->isFuture()) {
            return;
        }

        $tierAtLock = (int) ($user->login_lockout_tier ?? 0);
        $tiers = $this->tiers();
        $lastTierIndex = count($tiers) - 1;

        $user->locked_until = null;
        $user->failed_login_attempts = 0;

        if ($tierAtLock >= $lastTierIndex) {
            $user->login_lockout_tier = 0;
            $user->login_lockout_final_chance = true;
        } else {
            $user->login_lockout_tier = min($lastTierIndex, $tierAtLock + 1);
        }

        $user->save();
    }

    private function maxAttemptsForTier(User $user): int
    {
        $tierIndex = min(count($this->tiers()) - 1, max(0, (int) ($user->login_lockout_tier ?? 0)));

        return $this->tiers()[$tierIndex]['attempts'];
    }

    private function lockMinutesForTier(User $user): int
    {
        $tierIndex = min(count($this->tiers()) - 1, max(0, (int) ($user->login_lockout_tier ?? 0)));

        return $this->tiers()[$tierIndex]['minutes'];
    }

    private function shouldPermanentlyBan(User $user): bool
    {
        return (int) ($user->login_lockout_tier ?? 0) === 0
            && (bool) ($user->login_lockout_final_chance ?? false);
    }

    private function applyTierLock(Request $request, User $user, string $username, ?string $context = null): void
    {
        $minutes = $this->lockMinutesForTier($user);
        $user->locked_until = now()->addMinutes($minutes);

        $meta = [
            'failed_attempts' => $user->failed_login_attempts,
            'tier' => (int) $user->login_lockout_tier,
            'lock_minutes' => $minutes,
            'locked_until' => $user->locked_until->toIso8601String(),
        ];

        if ($context) {
            $meta['context'] = $context;
        }

        $this->audit->log(AuthEventType::ACCOUNT_LOCKED, $request, $user, $username, $meta);
    }

    private function applyPermanentBan(Request $request, User $user, string $username): void
    {
        $user->banido = true;
        $user->locked_until = null;
        $user->failed_login_attempts = 0;

        $this->audit->log(AuthEventType::LOGIN_PERMANENT_BAN, $request, $user, $username, [
            'reason' => 'excess_login_failures_after_final_chance',
        ]);

        Log::channel('security')->warning('Conta banida permanentemente por tentativas de login', [
            'username' => $username,
            'user_id' => $user->id,
        ]);
    }

    private function resetLockoutState(User $user): void
    {
        $user->failed_login_attempts = 0;
        $user->locked_until = null;
        $user->login_lockout_tier = 0;
        $user->login_lockout_final_chance = false;
    }

    private function tempTokenKey(object $decoded): string
    {
        $jti = $decoded->jti ?? 'unknown';

        return '2fa-temp-fail|'.$jti;
    }

    private function hitIpLimiter(Request $request): void
    {
        $decay = max(60, (int) config('auth_security.decay_seconds', 900));
        RateLimiter::hit($this->ipKey($request), $decay);
    }

    private function ipKey(Request $request): string
    {
        return 'login-fail|ip:'.$request->ip();
    }

    private function accountKey(string $username): string
    {
        return 'login-fail|user:'.Str::transliterate(Str::lower($username));
    }
}
