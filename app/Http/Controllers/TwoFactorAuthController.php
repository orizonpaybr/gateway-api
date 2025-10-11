<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use PragmaRX\Google2FA\Google2FA;
use PragmaRX\Google2FAQRCode\Google2FA as Google2FAQRCode;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use App\Models\User;

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
            /** @var \App\Models\User|null $user */
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
            
            Log::info('QR Code gerado para usuário: ' . $user->email . ' (label: ' . $accountLabel . ', issuer: ' . config('google2fa.issuer') . ')');
            Log::info('QR Code URL: ' . $qrCodeUrl);
            Log::info('QR Code SVG length: ' . strlen($qrCode));

            return response()->json([
                'success' => true,
                'qr_code' => $qrCode,
                'secret' => $user->twofa_secret,
                'manual_entry_key' => $user->twofa_secret
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao gerar QR Code 2FA: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erro interno: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verificar código 2FA (PIN)
     */
    public function verifyCode(Request $request)
    {
        $request->validate([
            'code' => 'required|string|size:6'
        ]);

        $user = $this->getUserFromRequest($request);
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401)->header('Access-Control-Allow-Origin', '*');
        }
        
        if (!$user->twofa_pin) {
            return response()->json([
                'success' => false,
                'message' => '2FA não configurado'
            ], 400)->header('Access-Control-Allow-Origin', '*');
        }

        // Verificar se o PIN está correto
        if (Hash::check($request->code, $user->twofa_pin)) {
            return response()->json([
                'success' => true,
                'message' => 'PIN válido'
            ])->header('Access-Control-Allow-Origin', '*');
        }

        return response()->json([
            'success' => false,
            'message' => 'PIN inválido'
        ], 400)->header('Access-Control-Allow-Origin', '*');
    }

    /**
     * Ativar 2FA
     */
    public function enable(Request $request)
    {
        try {
            Log::info('Tentativa de ativar 2FA', [
                'request_data' => $request->all(),
                'user_agent' => $request->userAgent(),
                'ip' => $request->ip()
            ]);
            
            $request->validate([
                'code' => 'required|string|size:6'
            ]);

            $user = $this->getUserFromRequest($request);
            
            if (!$user) {
                Log::error('Usuário não autenticado', [
                    'token' => $request->input('token') ? 'presente' : 'ausente',
                    'secret' => $request->input('secret') ? 'presente' : 'ausente'
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }
            
            Log::info('Usuário encontrado', [
                'user_id' => $user->id,
                'username' => $user->username,
                'status' => $user->status
            ]);
            
            // Para PIN-based 2FA, não precisamos verificar twofa_secret
            // O PIN será salvo diretamente

            Log::info('Salvando PIN e ativando 2FA para usuário: ' . $user->id);
            
            // Salvar o PIN criptografado
            $user->twofa_pin = bcrypt($request->code);
            $user->twofa_enabled = true;
            $user->twofa_enabled_at = now();
            $user->save();

            Log::info('2FA ativado com sucesso para usuário: ' . $user->id);
            return response()->json([
                'success' => true,
                'message' => '2FA ativado com sucesso'
            ])->header('Access-Control-Allow-Origin', '*');
        } catch (\Exception $e) {
            Log::error('Erro ao ativar 2FA: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            return response()->json([
                'success' => false,
                'message' => 'Erro interno: ' . $e->getMessage()
            ], 500)->header('Access-Control-Allow-Origin', '*');
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

        $user = $this->getUserFromRequest($request);
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401)->header('Access-Control-Allow-Origin', '*');
        }
        
        if (!$user->twofa_enabled) {
            return response()->json([
                'success' => false,
                'message' => '2FA não está ativado'
            ], 400)->header('Access-Control-Allow-Origin', '*');
        }

        $valid = $this->google2fa->verifyKey($user->twofa_secret, $request->code);

        if ($valid) {
            $user->twofa_enabled = false;
            $user->twofa_enabled_at = null;
            $user->save();

            return response()->json([
                'success' => true,
                'message' => '2FA desativado com sucesso'
            ])->header('Access-Control-Allow-Origin', '*');
        }

        return response()->json([
            'success' => false,
            'message' => 'Código inválido'
        ], 400)->header('Access-Control-Allow-Origin', '*');
    }

    /**
     * Verificar status do 2FA
     */
    public function status(Request $request)
    {
        try {
            $user = $this->getUserFromRequest($request);
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }
            
            return response()->json([
                'success' => true,
                'enabled' => $user->twofa_enabled ?? false,
                'configured' => !empty($user->twofa_secret),
                'enabled_at' => $user->twofa_enabled_at
            ])->header('Access-Control-Allow-Origin', '*');
        } catch (\Exception $e) {
            Log::error('Erro ao verificar status 2FA: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erro interno: ' . $e->getMessage()
            ], 500)->header('Access-Control-Allow-Origin', '*');
        }
    }

    /**
     * Obter usuário do request (usando middleware check.token.secret)
     */
    private function getUserFromRequest(Request $request)
    {
        try {
            // O middleware check.token.secret já validou o token e secret
            // e adicionou o usuário ao request
            $token = $request->input('token');
            $secret = $request->input('secret');
            
            if (!$token || !$secret) {
                return null;
            }

            // Buscar as chaves do usuário
            $userKeys = \App\Models\UsersKey::where('token', $token)
                ->where('secret', $secret)
                ->first();
            
            if (!$userKeys) {
                return null;
            }

            // Buscar o usuário
            $user = User::where('username', $userKeys->user_id)->first();
            
            return $user;
        } catch (\Exception $e) {
            Log::error('Erro ao obter usuário do request: ' . $e->getMessage());
            return null;
        }
    }
}
