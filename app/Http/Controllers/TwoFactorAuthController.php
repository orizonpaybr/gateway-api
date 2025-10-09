<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use PragmaRX\Google2FA\Google2FA;
use PragmaRX\Google2FAQRCode\Google2FA as Google2FAQRCode;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class TwoFactorAuthController extends Controller
{
    protected $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA();
    }

    /**
     * Gerar QR Code para configuração do 2FA
     */
    public function generateQrCode(Request $request)
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }
            
            // Gerar chave secreta se não existir
            if (!$user->twofa_secret) {
                $user->twofa_secret = $this->google2fa->generateSecretKey();
                $user->save();
            }

            // Definir label a ser exibido no app autenticador: preferir username, depois name, por fim email
            $accountLabel = $user->username ?? $user->name ?? $user->email;
            // Gerar URL para o app autenticador com issuer vindo da config (APP_NAME por padrão)
            $qrCodeUrl = $this->google2fa->getQRCodeUrl(
                config('google2fa.issuer'),
                $accountLabel,
                $user->twofa_secret
            );

            // Gerar QR Code como SVG
            $qrCode = (string) QrCode::size(200)->generate($qrCodeUrl);
            
            \Log::info('QR Code gerado para usuário: ' . $user->email . ' (label: ' . $accountLabel . ', issuer: ' . config('google2fa.issuer') . ')');
            \Log::info('QR Code URL: ' . $qrCodeUrl);
            \Log::info('QR Code SVG length: ' . strlen($qrCode));

            return response()->json([
                'success' => true,
                'qr_code' => $qrCode,
                'secret' => $user->twofa_secret,
                'manual_entry_key' => $user->twofa_secret
            ]);
        } catch (\Exception $e) {
            \Log::error('Erro ao gerar QR Code 2FA: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erro interno: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verificar código 2FA
     */
    public function verifyCode(Request $request)
    {
        $request->validate([
            'code' => 'required|string|size:6'
        ]);

        $user = Auth::user();
        
        if (!$user->twofa_secret) {
            return response()->json([
                'success' => false,
                'message' => '2FA não configurado'
            ], 400);
        }

        $valid = $this->google2fa->verifyKey($user->twofa_secret, $request->code);

        if ($valid) {
            return response()->json([
                'success' => true,
                'message' => 'Código válido'
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Código inválido'
        ], 400);
    }

    /**
     * Ativar 2FA
     */
    public function enable(Request $request)
    {
        try {
            \Log::info('Tentativa de ativar 2FA para usuário: ' . Auth::id());
            
            $request->validate([
                'code' => 'required|string|size:6'
            ]);

            $user = Auth::user();
            
            if (!$user) {
                \Log::error('Usuário não autenticado');
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }
            
            if (!$user->twofa_secret) {
                \Log::error('2FA não configurado para usuário: ' . $user->id);
                return response()->json([
                    'success' => false,
                    'message' => '2FA não configurado'
                ], 400);
            }

            \Log::info('Verificando código 2FA: ' . $request->code);
            $valid = $this->google2fa->verifyKey($user->twofa_secret, $request->code);

            if ($valid) {
                \Log::info('Código válido, ativando 2FA para usuário: ' . $user->id);
                $user->twofa_enabled = true;
                $user->twofa_enabled_at = now();
                $user->save();

                \Log::info('2FA ativado com sucesso para usuário: ' . $user->id);
                return response()->json([
                    'success' => true,
                    'message' => '2FA ativado com sucesso'
                ]);
            }

            \Log::warning('Código inválido para usuário: ' . $user->id);
            return response()->json([
                'success' => false,
                'message' => 'Código inválido'
            ], 400);
        } catch (\Exception $e) {
            \Log::error('Erro ao ativar 2FA: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());
            return response()->json([
                'success' => false,
                'message' => 'Erro interno: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Desativar 2FA
     */
    public function disable(Request $request)
    {
        $request->validate([
            'code' => 'required|string|size:6'
        ]);

        $user = Auth::user();
        
        if (!$user->twofa_enabled) {
            return response()->json([
                'success' => false,
                'message' => '2FA não está ativado'
            ], 400);
        }

        $valid = $this->google2fa->verifyKey($user->twofa_secret, $request->code);

        if ($valid) {
            $user->twofa_enabled = false;
            $user->twofa_enabled_at = null;
            $user->save();

            return response()->json([
                'success' => true,
                'message' => '2FA desativado com sucesso'
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Código inválido'
        ], 400);
    }

    /**
     * Verificar status do 2FA
     */
    public function status()
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }
            
            return response()->json([
                'enabled' => $user->twofa_enabled ?? false,
                'configured' => !empty($user->twofa_secret),
                'enabled_at' => $user->twofa_enabled_at
            ]);
        } catch (\Exception $e) {
            \Log::error('Erro ao verificar status 2FA: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erro interno: ' . $e->getMessage()
            ], 500);
        }
    }
}
