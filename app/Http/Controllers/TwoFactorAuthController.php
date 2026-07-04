<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use PragmaRX\Google2FA\Google2FA;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use App\Models\User;
use App\Services\TwoFactorVerificationService;
use App\Services\TotpMigrationService;

class TwoFactorAuthController extends Controller
{
    public function __construct(
        private Google2FA $google2fa,
        private TwoFactorVerificationService $twoFactorVerification,
        private TotpMigrationService $totpMigration,
    ) {}

    public function generateQrCode(Request $request)
    {
        /** @var User|null $user */
        $user = $request->user() ?? $request->user_auth ?? Auth::user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado',
            ], 401);
        }

        $secret = $this->google2fa->generateSecretKey();
        $issuer = config('auth_security.totp_issuer', 'Coratri Finance');
        $otpUrl = $this->google2fa->getQRCodeUrl($issuer, $user->email ?? $user->username, $secret);

        $renderer = new ImageRenderer(
            new RendererStyle(200),
            new SvgImageBackEnd(),
        );
        $writer = new Writer($renderer);
        $qrSvg = $writer->writeString($otpUrl);

        cache()->put('twofa_setup:'.$user->id, $secret, 600);

        return response()->json([
            'success' => true,
            'data' => [
                'qr_svg' => 'data:image/svg+xml;base64,'.base64_encode($qrSvg),
            ],
        ]);
    }

    public function verifyCode(Request $request)
    {
        $request->validate([
            'code' => 'required|string|size:6',
        ]);

        /** @var User|null $user */
        $user = $request->user() ?? $request->user_auth ?? Auth::user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado',
            ], 401);
        }

        if ($this->twoFactorVerification->verify($user, $request->code)) {
            return response()->json([
                'success' => true,
                'message' => 'Código válido',
            ]);
        }

        $message = $this->totpMigration->mustMigrateBeforeVerify($user)
            ? $this->twoFactorVerification->migrationRequiredMessage()
            : 'Código inválido';

        return response()->json([
            'success' => false,
            'message' => $message,
            'requires_totp_migration' => $this->totpMigration->mustMigrateBeforeVerify($user),
        ], 400);
    }

    public function enable(Request $request)
    {
        try {
            $request->validate([
                'code' => 'required|string|size:6',
            ]);

            /** @var User|null $user */
            $user = $request->user() ?? $request->user_auth ?? Auth::user();

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado',
                ], 401);
            }

            $pendingSecret = cache()->get('twofa_setup:'.$user->id);

            if (! $pendingSecret) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sessão de configuração expirada. Gere um novo QR Code.',
                ], 400);
            }

            if (! $this->google2fa->verifyKey($pendingSecret, $request->code)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Código inválido. Verifique o app autenticador.',
                ], 400);
            }

            $user->twofa_secret = encrypt($pendingSecret);
            $user->twofa_method = 'totp';
            $user->twofa_pin = null;
            $user->twofa_enabled = true;
            $user->twofa_enabled_at = now();
            $user->save();

            cache()->forget('twofa_setup:'.$user->id);

            Log::info('2FA TOTP ativado', ['user_id' => $user->id]);

            return response()->json([
                'success' => true,
                'message' => '2FA ativado com sucesso',
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao ativar 2FA: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erro interno ao ativar 2FA',
            ], 500);
        }
    }

    public function disable(Request $request)
    {
        $request->validate([
            'code' => 'required|string|size:6',
        ]);

        /** @var User|null $user */
        $user = $request->user() ?? $request->user_auth ?? Auth::user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado',
            ], 401);
        }

        if (! $user->twofa_enabled) {
            return response()->json([
                'success' => false,
                'message' => '2FA não está ativado',
            ], 400);
        }

        if ($this->twoFactorVerification->verify($user, $request->code)) {
            $user->twofa_enabled = false;
            $user->twofa_pin = null;
            $user->twofa_secret = null;
            $user->twofa_method = null;
            $user->save();

            return response()->json([
                'success' => true,
                'message' => '2FA desativado com sucesso',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Código inválido',
        ], 400);
    }

    public function status(Request $request)
    {
        try {
            /** @var User|null $user */
            $user = $request->user() ?? $request->user_auth ?? Auth::user();

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado',
                ], 401);
            }

            return response()->json([
                'success' => true,
                'enabled' => $user->twofa_enabled ?? false,
                'configured' => ! is_null($user->twofa_enabled_at),
                'method' => $user->twofa_method,
                'requires_totp_migration' => $this->totpMigration->requiresMigration($user),
                'migration_deadline_passed' => $this->totpMigration->migrationDeadlinePassed(),
                'enabled_at' => $user->twofa_enabled_at,
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao verificar status 2FA: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erro interno',
            ], 500);
        }
    }
}
