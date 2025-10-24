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
        return response()->json([
            'success' => false,
            'message' => 'Fluxo de QR Code desativado. O sistema usa apenas PIN.',
        ], 410);
    }

    /**
     * Verificar código 2FA (PIN)
     */
    public function verifyCode(Request $request)
    {
        $request->validate([
            'code' => 'required|string|size:6'
        ]);

        // Usar usuário autenticado via JWT
        /** @var \App\Models\User|null $user */
        $user = $request->user() ?? $request->user_auth ?? Auth::user();
        
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

            // Usar usuário autenticado via JWT
            /** @var \App\Models\User|null $user */
            $user = $request->user() ?? $request->user_auth ?? Auth::user();
            
            if (!$user) {
                Log::error('Usuário não autenticado via JWT');
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

        // Usar usuário autenticado via JWT
        /** @var \App\Models\User|null $user */
        $user = $request->user() ?? $request->user_auth ?? Auth::user();
        
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

        // Para PIN-based 2FA, verificar o PIN diretamente
        $valid = Hash::check($request->code, $user->twofa_pin);

        if ($valid) {
            $user->twofa_enabled = false;
            // ❌ NÃO apagar twofa_enabled_at - mantém histórico de que já foi configurado
            // $user->twofa_enabled_at = null;
            $user->twofa_pin = null; // Limpar o PIN quando desativar
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
            // Usar usuário autenticado via JWT
            /** @var \App\Models\User|null $user */
            $user = $request->user() ?? $request->user_auth ?? Auth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }
            
            return response()->json([
                'success' => true,
                'enabled' => $user->twofa_enabled ?? false,
                // 'configured' = true se foi configurado ALGUMA VEZ (tem enabled_at)
                // Não pode usar twofa_pin porque é deletado quando desativa
                'configured' => !is_null($user->twofa_enabled_at),
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
}
