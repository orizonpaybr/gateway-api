<?php

namespace App\Constants;

class AuthEventType
{
    public const LOGIN_SUCCESS = 'login_success';
    public const LOGIN_FAILED = 'login_failed';
    public const LOCKOUT = 'lockout';
    public const ACCOUNT_LOCKED = 'account_locked';
    public const TWO_FA_REQUIRED = '2fa_required';
    public const TWO_FA_SUCCESS = '2fa_success';
    public const TWO_FA_FAILED = '2fa_failed';
    public const TWO_FA_SESSION_TERMINATED = '2fa_session_terminated';
    public const LOGOUT = 'logout';
    public const CAPTCHA_REQUIRED = 'captcha_required';
    public const CAPTCHA_FAILED = 'captcha_failed';
    public const IP_BLOCKED = 'ip_blocked';
    public const REGISTER = 'register';
    public const LOGIN_UNLOCKED = 'login_unlocked';
    public const LOGIN_PERMANENT_BAN = 'login_permanent_ban';
}
