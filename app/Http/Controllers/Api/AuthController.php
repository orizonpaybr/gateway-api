<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\RegisterUserRequest;
use App\Http\Requests\Api\Auth\ApiLoginRequest;
use App\Http\Requests\Api\Auth\Verify2FARequest;
use App\Models\User;
use App\Models\UsersKey;
use App\Constants\UserStatus;
use App\Constants\UserPermission;
use App\Constants\AuthEventType;
use App\Helpers\UserStatusHelper;
use App\Services\JWTService;
use App\Services\LoginLockoutService;
use App\Services\AuthAuditService;
use App\Services\TurnstileVerificationService;
use App\Services\JwtBlacklistService;
use App\Services\TwoFactorVerificationService;
use App\Services\TotpMigrationService;
use App\Services\AuthRegistrationService;
use App\Support\AuthUserPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    public function __construct(
        private JWTService $jwtService,
        private LoginLockoutService $lockout,
        private AuthAuditService $audit,
        private TurnstileVerificationService $turnstile,
        private JwtBlacklistService $jwtBlacklist,
        private TwoFactorVerificationService $twoFactorVerification,
        private TotpMigrationService $totpMigration,
        private AuthRegistrationService $registration,
    ) {}

    private function invalidCredentialsResponse(Request $request, ?User $user, string $username): \Illuminate\Http\JsonResponse
    {
        $failure = $this->lockout->recordFailedAttempt($request, $user, $username);

        if ($failure['banned']) {
            return response()->json([
                'success' => false,
                'message' => 'Conta bloqueada permanentemente por excesso de tentativas. Entre em contato com o suporte.',
                'account_banned' => true,
            ], 403);
        }

        if ($failure['locked']) {
            return response()->json([
                'success' => false,
                'message' => 'Conta temporariamente bloqueada por excesso de tentativas. Tente novamente mais tarde.',
                'retry_after' => $failure['retry_after'],
            ], 429)->header('Retry-After', (string) $failure['retry_after']);
        }

        $payload = [
            'success' => false,
            'message' => 'Credenciais inválidas',
        ];

        if ($this->lockout->requiresCaptcha($request)) {
            $payload['requires_captcha'] = true;
        }

        return response()->json($payload, 401);
    }

    private function validateTurnstileIfRequired(Request $request, bool $always = false): ?\Illuminate\Http\JsonResponse
    {
        if (! $this->turnstile->isConfigured()) {
            return null;
        }

        $required = $always || $this->lockout->requiresCaptcha($request);

        if ($required && empty($request->input('turnstile_token'))) {
            $this->audit->log(AuthEventType::CAPTCHA_REQUIRED, $request, null, $request->input('username'));
        }

        if (! $required) {
            return null;
        }

        if (! $this->turnstile->verify($request->input('turnstile_token'), $request->ip())) {
            $this->audit->log(AuthEventType::CAPTCHA_FAILED, $request, null, $request->input('username'));

            return response()->json([
                'success' => false,
                'message' => 'Verificação de segurança inválida. Tente novamente.',
                'requires_captcha' => true,
            ], 422);
        }

        return null;
    }

    private function validateTurnstileForTwoFa(Request $request, User $user): ?\Illuminate\Http\JsonResponse
    {
        if (! $this->turnstile->isConfigured()) {
            return null;
        }

        if (! $this->lockout->requiresTwoFaCaptcha($request)) {
            return null;
        }

        if (empty($request->input('turnstile_token'))) {
            $this->audit->log(AuthEventType::CAPTCHA_REQUIRED, $request, $user, $user->username, [
                'context' => 'verify-2fa',
            ]);
        }

        if (! $this->turnstile->verify($request->input('turnstile_token'), $request->ip())) {
            $this->audit->log(AuthEventType::CAPTCHA_FAILED, $request, $user, $user->username, [
                'context' => 'verify-2fa',
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Verificação de segurança inválida. Tente novamente.',
                'requires_captcha' => true,
            ], 422);
        }

        return null;
    }

    private function blockedTwoFaResponse(array $result): \Illuminate\Http\JsonResponse
    {
        $status = $result['status'];
        $payload = $result['payload'];
        $response = response()->json($payload, $status);

        if (isset($payload['retry_after'])) {
            $response->header('Retry-After', (string) $payload['retry_after']);
        }

        return $response;
    }
    
    /**
     * Login do usuário via API
     */
    public function login(ApiLoginRequest $request)
    {
        try {
            $username = $request->input('username');
            $password = $request->input('password');

            if ($captchaError = $this->validateTurnstileIfRequired($request)) {
                return $captchaError;
            }

            // Buscar usuário pelo username ou email
            $user = User::where('username', $username)
                       ->orWhere('email', $username)
                       ->first();

            if ($user && $user->banido) {
                return response()->json([
                    'success' => false,
                    'message' => 'Conta bloqueada permanentemente por excesso de tentativas. Entre em contato com o suporte.',
                    'account_banned' => true,
                ], 403);
            }

            if ($user && $this->lockout->isLocked($user)) {
                $this->audit->log(AuthEventType::LOCKOUT, $request, $user, $username);
                $locked = $this->lockout->lockedJsonResponse(
                    $user,
                    'Conta temporariamente bloqueada por excesso de tentativas. Tente novamente mais tarde.',
                );

                return response()->json($locked['payload'], $locked['status'])
                    ->header('Retry-After', (string) ($locked['payload']['retry_after'] ?? 0));
            }

            if (! $user || ! Hash::check($password, $user->password)) {
                Log::warning('Tentativa de login com credenciais inválidas', [
                    'username' => $username,
                    'ip' => $request->ip(),
                ]);

                return $this->invalidCredentialsResponse($request, $user, $username);
            }

            $this->lockout->clearFailedAttempts($user);

            // Verificar se usuário pode fazer login
            if (!UserStatusHelper::canLogin($user)) {
                $message = $user->status == UserStatus::PENDING
                    ? 'Sua conta está aguardando aprovação. Você poderá acessar o dashboard após aprovação pelo administrador.'
                    : 'Sua conta foi desativada ou bloqueada. Entre em contato com o suporte.';

                Log::warning('Tentativa de login com conta não permitida', [
                    'username' => $username,
                    'status' => $user->status,
                    'banido' => $user->banido,
                    'ip' => $request->ip()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $message
                ], 403);
            }

            // Configurar 2FA (primeiro acesso / admin obrigatório) — QR Code
            if ($this->mustSetupTwoFactor($user)) {
                return $this->pendingTwoFactorSetupResponse($request, $user, $username);
            }

            // Verificar 2FA já configurado (TOTP ou PIN legado)
            if ($user->twofa_enabled && $this->isTwoFactorConfigured($user)) {
                // Gerar token temporário para verificação 2FA (usando JWT real)
                $tempToken = $this->jwtService->generate2FAToken($user->username);

                $this->audit->log(AuthEventType::TWO_FA_REQUIRED, $request, $user, $username);

                Log::info('Login requer verificação 2FA', [
                    'username' => $username,
                    'ip' => $request->ip(),
                    'twofa_method' => $user->twofa_method ?? 'pin',
                ]);

                $requiresTotpMigration = $this->totpMigration->requiresMigration($user);

                return response()->json([
                    'success' => false,
                    'requires_2fa' => true,
                    'twofa_method' => $user->twofa_method ?? ($user->twofa_secret ? 'totp' : 'pin'),
                    'requires_totp_migration' => $requiresTotpMigration,
                    'migration_deadline_passed' => $this->totpMigration->migrationDeadlinePassed(),
                    'message' => $requiresTotpMigration && $this->totpMigration->migrationDeadlinePassed()
                        ? $this->twoFactorVerification->migrationRequiredMessage()
                        : 'Digite o código de 6 dígitos do seu app autenticador',
                    'temp_token' => $tempToken,
                    'data' => [
                        'user' => AuthUserPresenter::loginProfile($user),
                    ],
                ], 200);
            }

            // Buscar as chaves do usuário
            $userKeys = UsersKey::where('user_id', $user->username)->first();

            if (!$userKeys) {
                Log::warning('Usuário sem chaves de API configuradas', [
                    'username' => $username,
                    'ip' => $request->ip()
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário sem chaves de API configuradas'
                ], 401);
            }

            // Gerar token JWT real com assinatura criptográfica
            // Não incluímos dados sensíveis (token/secret) no JWT!
            $token = $this->jwtService->generateToken($user->username, [
                'permission' => $user->permission,
            ]);

            // Obter token e secret descriptografados (accessors do Model fazem isso automaticamente)
            $apiToken = $userKeys->token; // Accessor descriptografa
            $apiSecret = $userKeys->secret; // Accessor descriptografa

            Log::info('Login bem-sucedido via API', [
                'username' => $username,
                'ip' => $request->ip()
            ]);

            $this->audit->log(AuthEventType::LOGIN_SUCCESS, $request, $user, $username);
            $this->lockout->clearIpLimiter($request);

            return response()->json([
                'success' => true,
                'message' => 'Login realizado com sucesso',
                'data' => [
                    'user' => AuthUserPresenter::loginProfile($user),
                    'token' => $token,
                    'api_token' => $apiToken,
                    'api_secret' => $apiSecret,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erro no login da API', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    /**
     * Verificar código 2FA
     */
    public function verify2FA(Verify2FARequest $request)
    {
        try {
            $tempToken = $request->input('temp_token');
            $code = $request->input('code');

            $decoded = $this->jwtService->validateToken($tempToken);

            if (! $decoded) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sessão de verificação expirada ou inválida. Faça login novamente.',
                    'session_terminated' => true,
                ], 401);
            }

            if (! isset($decoded->temp) || $decoded->temp !== true
                || ! in_array($decoded->purpose ?? '', ['2fa_verification', '2fa_setup'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token temporário inválido',
                    'session_terminated' => true,
                ], 401);
            }

            $user = User::where('username', $decoded->sub)->first();

            if (! $user || ! $user->twofa_enabled || (! $user->twofa_pin && ! $user->twofa_secret)) {
                $this->jwtBlacklist->blacklistFromToken($decoded);

                return response()->json([
                    'success' => false,
                    'message' => 'Sessão de verificação inválida. Faça login novamente.',
                    'session_terminated' => true,
                ], 401);
            }

            if ($blocked = $this->lockout->checkTwoFaAllowed($request, $user)) {
                $this->jwtBlacklist->blacklistFromToken($decoded);

                return $this->blockedTwoFaResponse($blocked);
            }

            if (! UserStatusHelper::canLogin($user)) {
                $message = $user->status == UserStatus::PENDING
                    ? 'Sua conta está aguardando aprovação. Você poderá acessar o dashboard após aprovação pelo administrador.'
                    : 'Sua conta foi desativada ou bloqueada. Entre em contato com o suporte.';
                $this->jwtBlacklist->blacklistFromToken($decoded);
                Log::warning('Tentativa de login 2FA com conta não permitida', [
                    'username' => $user->username,
                    'status' => $user->status,
                    'banido' => $user->banido,
                    'ip' => $request->ip(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'session_terminated' => true,
                ], 403);
            }

            if ($captchaError = $this->validateTurnstileForTwoFa($request, $user)) {
                return $captchaError;
            }

            if ($this->totpMigration->mustMigrateBeforeVerify($user)) {
                $this->jwtBlacklist->blacklistFromToken($decoded);

                return response()->json([
                    'success' => false,
                    'message' => $this->twoFactorVerification->migrationRequiredMessage(),
                    'requires_totp_migration' => true,
                    'session_terminated' => true,
                ], 403);
            }

            $valid = $this->twoFactorVerification->verify($user, $code);

            if (! $valid) {
                $failure = $this->lockout->recordTwoFaFailedAttempt(
                    $request,
                    $user,
                    $decoded,
                    $this->jwtBlacklist,
                );

                Log::warning('Código 2FA inválido', [
                    'username' => $user->username,
                    'ip' => $request->ip(),
                    'temp_failures' => $failure['temp_failures'],
                ]);

                if ($failure['banned']) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Conta bloqueada permanentemente por excesso de tentativas. Entre em contato com o suporte.',
                        'session_terminated' => true,
                        'account_banned' => true,
                    ], 403);
                }

                if ($failure['session_terminated'] || $failure['account_locked']) {
                    $retryAfter = $failure['retry_after'] > 0
                        ? $failure['retry_after']
                        : max(60, $this->lockout->retryAfterSeconds($user->fresh()));

                    return response()->json([
                        'success' => false,
                        'message' => 'Muitas tentativas inválidas. Sessão encerrada — faça login novamente.',
                        'session_terminated' => true,
                        'requires_login' => true,
                        'retry_after' => $retryAfter,
                    ], 429)->header('Retry-After', (string) $retryAfter);
                }

                if ($blocked = $this->lockout->checkTwoFaAllowed($request, $user->fresh())) {
                    $this->jwtBlacklist->blacklistFromToken($decoded);

                    return $this->blockedTwoFaResponse($blocked);
                }

                $payload = [
                    'success' => false,
                    'message' => 'Código inválido',
                ];

                if ($this->lockout->requiresTwoFaCaptcha($request)) {
                    $payload['requires_captcha'] = true;
                }

                return response()->json($payload, 400);
            }

            $this->lockout->clearFailedAttempts($user);
            $this->lockout->clearIpLimiter($request);

            // Buscar as chaves do usuário
            $userKeys = UsersKey::where('user_id', $user->username)->first();

            if (!$userKeys) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário sem chaves de API configuradas'
                ], 401);
            }

            // Gerar token JWT final (sem dados sensíveis!)
            $token = $this->jwtService->generateToken($user->username, [
                'permission' => $user->permission,
            ]);

            Log::info('Login 2FA bem-sucedido via API', [
                'username' => $user->username,
                'ip' => $request->ip()
            ]);

            $this->audit->log(AuthEventType::TWO_FA_SUCCESS, $request, $user, $user->username);
            $this->audit->log(AuthEventType::LOGIN_SUCCESS, $request, $user, $user->username);

            return response()->json([
                'success' => true,
                'message' => 'Login realizado com sucesso',
                'data' => [
                    'user' => AuthUserPresenter::loginProfile($user),
                    'token' => $token,
                    'api_token' => $userKeys->token,
                    'api_secret' => $userKeys->secret,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erro na verificação 2FA da API', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    /**
     * Verificar token válido
     */
    public function verifyToken(Request $request)
    {
        try {
            // Com middleware verify.jwt, o usuário já está disponível
            $user = $request->user() ?? $request->user_auth;
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não encontrado'
                ], 401);
            }

            // Verificar se usuário pode fazer login
            if (!UserStatusHelper::canLogin($user)) {
                $message = $user->status == UserStatus::PENDING
                    ? 'Sua conta está aguardando aprovação. Você poderá acessar o dashboard após aprovação pelo administrador.'
                    : 'Sua conta foi desativada ou bloqueada. Entre em contato com o suporte.';
                Log::warning('Tentativa de verificar token com conta não permitida', [
                    'username' => $user->username,
                    'status' => $user->status,
                    'banido' => $user->banido,
                    'ip' => $request->ip()
                ]);
                return response()->json([
                    'success' => false,
                    'message' => $message
                ], 403);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $user->username,
                        'username' => $user->username,
                        'email' => $user->email ?? '',
                        'name' => $user->name ?? $user->username,
                        'status' => $user->status ?? 1,
                        'status_text' => $user->status_text ?? 'Ativo',
                        'agency' => $user->agency ?? '',
                        'balance' => $user->balance ?? 0,
                        'phone' => $user->phone ?? '',
                        'cnpj' => $user->cnpj ?? '',
                        'twofa_enabled' => $user->twofa_enabled ?? false,
                        'twofa_configured' => $user->twofa_configured ?? false,
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erro na verificação do token', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Token inválido'
            ], 401);
        }
    }

    /**
     * Logout
     */
    public function logout(Request $request)
    {
        $token = $request->bearerToken();

        if ($token) {
            $decoded = $this->jwtService->validateToken($token);
            if ($decoded) {
                $this->jwtBlacklist->blacklistFromToken($decoded);
            }
        }

        $user = $request->user() ?? $request->user_auth;
        $this->audit->log(AuthEventType::LOGOUT, $request, $user);

        Log::info('Logout realizado', [
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Logout realizado com sucesso'
        ]);
    }

    /**
     * Registro de novo usuário via API
     */
    public function register(RegisterUserRequest $request)
    {
        try {
            if ($captchaError = $this->validateTurnstileIfRequired($request, always: true)) {
                return $captchaError;
            }

            $user = $this->registration->register($request);

            Log::info('Usuário registrado com sucesso via API', [
                'username' => $request->username,
                'ip' => $request->ip(),
                'status' => 'pendente_aprovacao',
            ]);

            $this->audit->log(AuthEventType::REGISTER, $request, $user, $request->username);

            return response()->json([
                'success' => true,
                'message' => 'Cadastro realizado. Sua conta está aguardando aprovação. Você poderá fazer login após a aprovação pelo administrador.',
                'data' => [
                    'user' => AuthUserPresenter::registrationProfile($user),
                    'pending_approval' => true,
                ],
            ], 201);
        } catch (\Exception $e) {
            Log::error('Erro no registro via API', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'request_data' => [
                    'username' => $request->input('username'),
                    'email' => $request->input('email'),
                    'has_files' => $request->hasFile('documentoFrente') || $request->hasFile('documentoVerso') || $request->hasFile('selfieDocumento'),
                ],
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Validar dados únicos antes do cadastro
     */
    public function validateRegistrationData(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'username' => 'required|string|regex:/^[\pL\pN\s\'\-]+$/u|unique:users,username',
                'email' => 'required|string|lowercase|email|max:255|unique:users,email',
                'telefone' => 'nullable|string|unique:users,telefone',
                'cpf_cnpj' => 'nullable|string|unique:users,cpf_cnpj',
            ], [
                'username.unique' => 'Este nome de usuário já está em uso',
                'username.regex' => 'O campo nome de usuário aceita apenas letras, números, espaços, apóstrofos e hífens.',
                'email.unique' => 'Este email já está em uso',
                'email.email' => 'Email inválido',
                'telefone.unique' => 'Este telefone já está em uso',
                'cpf_cnpj.unique' => 'Este CPF/CNPJ já está em uso',
            ]);

            if ($validator->fails()) {
                $errors = $validator->errors();
                $errorMessages = [];
                
                if ($errors->has('username')) {
                    $errorMessages['username'] = $errors->first('username');
                }
                if ($errors->has('email')) {
                    $errorMessages['email'] = $errors->first('email');
                }
                if ($errors->has('telefone')) {
                    $errorMessages['telefone'] = $errors->first('telefone');
                }
                if ($errors->has('cpf_cnpj')) {
                    $errorMessages['cpf_cnpj'] = $errors->first('cpf_cnpj');
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Dados já existentes',
                    'errors' => $errorMessages
                ], 422);
            }

            return response()->json([
                'success' => true,
                'message' => 'Dados válidos',
                'data' => [
                    'username_available' => true,
                    'email_available' => true,
                    'telefone_available' => !$request->has('telefone') || $request->telefone === '',
                    'cpf_cnpj_available' => !$request->has('cpf_cnpj') || $request->cpf_cnpj === ''
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erro na validação de dados únicos', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    private function isTwoFactorConfigured(User $user): bool
    {
        if (! $user->twofa_secret) {
            return false;
        }

        return $user->twofa_method === 'totp' || ! $user->twofa_method;
    }

    private function mustSetupTwoFactor(User $user): bool
    {
        if ($this->isTwoFactorConfigured($user)) {
            return false;
        }

        if ($this->totpMigration->requiresMigration($user)) {
            return true;
        }

        if (config('auth_security.require_2fa_for_admins', true)
            && $user->permission === UserPermission::ADMIN) {
            return true;
        }

        return (bool) $user->twofa_enabled;
    }

    private function pendingTwoFactorSetupResponse(
        Request $request,
        User $user,
        string $username,
    ): \Illuminate\Http\JsonResponse {
        $tempToken = $this->jwtService->generate2FASetupToken($user->username);

        $this->audit->log(AuthEventType::TWO_FA_REQUIRED, $request, $user, $username, [
            'context' => 'setup',
        ]);

        Log::info('Login requer configuração 2FA', [
            'username' => $username,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'success' => false,
            'requires_2fa_setup' => true,
            'message' => 'Configure o Google Authenticator para continuar.',
            'temp_token' => $tempToken,
            'data' => [
                'user' => AuthUserPresenter::loginProfile($user),
            ],
        ], 200);
    }
}
