<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rate limiting — tentativas falhas de login
    |--------------------------------------------------------------------------
    */
    'max_attempts_per_ip' => (int) env('AUTH_MAX_ATTEMPTS_PER_IP', 10),
    'decay_seconds' => (int) env('AUTH_DECAY_SECONDS', 900),

    /*
    |--------------------------------------------------------------------------
    | Lockout progressivo por conta (tiers)
    | Tier 0: 3 tentativas → 15 min
    | Tier 1: 2 tentativas → 30 min
    | Tier 2: 1 tentativa  → 24 h
    | Após 24 h: volta ao tier 0 (3 tentativas)
    | Se errar 3 vezes de novo: banimento permanente (banido = true)
    |--------------------------------------------------------------------------
    */
    'lockout_tiers' => [
        ['attempts' => 3, 'minutes' => 15],
        ['attempts' => 2, 'minutes' => 30],
        ['attempts' => 1, 'minutes' => 1440],
    ],

    // Compatibilidade legada (não usado no lockout por tier)
    'max_attempts_per_account' => (int) env('AUTH_MAX_ATTEMPTS_PER_ACCOUNT', 5),
    'lockout_minutes' => (int) env('AUTH_LOCKOUT_MINUTES', 30),

    /*
    |--------------------------------------------------------------------------
    | Verificação 2FA (verify-2fa)
    |--------------------------------------------------------------------------
    */
    'max_2fa_attempts_per_temp_token' => (int) env('AUTH_MAX_2FA_ATTEMPTS_PER_TEMP_TOKEN', 5),
    '2fa_captcha_after_failures' => (int) env('AUTH_2FA_CAPTCHA_AFTER_FAILURES', 3),

    /*
    |--------------------------------------------------------------------------
    | CAPTCHA progressivo (Cloudflare Turnstile)
    |--------------------------------------------------------------------------
    */
    'captcha_after_failures' => (int) env('AUTH_CAPTCHA_AFTER_FAILURES', 3),
    'turnstile_site_key' => env('TURNSTILE_SITE_KEY'),
    'turnstile_secret_key' => env('TURNSTILE_SECRET_KEY'),
    'turnstile_verify_url' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
    'turnstile_fail_open' => (bool) env('TURNSTILE_FAIL_OPEN', false),

    /*
    |--------------------------------------------------------------------------
    | Retenção de auth_events
    |--------------------------------------------------------------------------
    */
    'auth_events_retention_days' => (int) env('AUTH_EVENTS_RETENTION_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | 2FA / TOTP
    |--------------------------------------------------------------------------
    */
    'totp_issuer' => env('AUTH_TOTP_ISSUER', 'Coratri Finance'),
    'totp_migration_deadline' => env('AUTH_TOTP_MIGRATION_DEADLINE'),
    'require_2fa_for_admins' => (bool) env('AUTH_REQUIRE_2FA_FOR_ADMINS', true),

    /*
    |--------------------------------------------------------------------------
    | JWT blacklist (Redis/Cache)
    |--------------------------------------------------------------------------
    */
    'jwt_blacklist_enabled' => (bool) env('JWT_BLACKLIST_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Monitoramento e alertas
    |--------------------------------------------------------------------------
    */
    'alert_window_minutes' => (int) env('AUTH_ALERT_WINDOW_MINUTES', 5),
    'alert_failed_login_threshold' => (int) env('AUTH_ALERT_FAILED_THRESHOLD', 50),
    'alert_2fa_failed_threshold' => (int) env('AUTH_ALERT_2FA_FAILED_THRESHOLD', 30),
    'alert_ip_failed_threshold' => (int) env('AUTH_ALERT_IP_FAILED_THRESHOLD', 10),
    'alert_slack_webhook' => env('AUTH_ALERT_SLACK_WEBHOOK', env('LOG_SLACK_WEBHOOK_URL')),

    /*
    |--------------------------------------------------------------------------
    | Reputação de IP (AbuseIPDB — opcional)
    |--------------------------------------------------------------------------
    */
    'ip_reputation_enabled' => (bool) env('AUTH_IP_REPUTATION_ENABLED', false),
    'abuseipdb_api_key' => env('ABUSEIPDB_API_KEY'),
    'abuseipdb_max_score' => (int) env('ABUSEIPDB_MAX_SCORE', 75),
    'ip_reputation_cache_ttl' => (int) env('AUTH_IP_REPUTATION_CACHE_TTL', 86400),

];
