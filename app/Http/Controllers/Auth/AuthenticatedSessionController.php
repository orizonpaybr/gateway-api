<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use PragmaRX\Google2FA\Google2FA;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();
        $request->session()->regenerate();

        $user = Auth::user();

        // Verificar se o usuário tem 2FA ativo
        if ($user->twofa_enabled && $user->twofa_secret) {
            // Armazenar dados do usuário temporariamente na sessão
            $request->session()->put('2fa_user_id', $user->id);
            $request->session()->put('2fa_remember', $request->boolean('remember'));
            
            // Fazer logout temporário
            Auth::logout();
            
            // Redirecionar para verificação 2FA
            return redirect()->route('2fa.verify')->with('info', 'Digite o código de 6 dígitos do seu app autenticador para continuar.');
        }

        // Redirecionar baseado no tipo de usuário
        if ($user->permission == 3) {
            return redirect()->route('admin.dashboard')->with('success', "Bem vindo de volta!");
        } else {
            return redirect()->route('dashboard')->with('success', "Bem vindo de volta!");
        }
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/login')->with('success', "Até breve!");
    }

    /**
     * Show 2FA verification form
     */
    public function show2FAForm(): View
    {
        if (!session('2fa_user_id')) {
            return redirect()->route('login')->with('error', 'Sessão expirada. Faça login novamente.');
        }

        return view('auth.2fa-verify');
    }

    /**
     * Verify 2FA code
     */
    public function verify2FA(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => 'required|string|size:6'
        ]);

        $userId = session('2fa_user_id');
        if (!$userId) {
            return redirect()->route('login')->with('error', 'Sessão expirada. Faça login novamente.');
        }

        $user = User::find($userId);
        if (!$user || !$user->twofa_enabled || !$user->twofa_secret) {
            return redirect()->route('login')->with('error', 'Usuário não encontrado ou 2FA não configurado.');
        }

        $google2fa = new Google2FA();
        $valid = $google2fa->verifyKey($user->twofa_secret, $request->code);

        if (!$valid) {
            return back()->withErrors(['code' => 'Código inválido. Tente novamente.']);
        }

        // Limpar dados temporários da sessão
        $request->session()->forget(['2fa_user_id', '2fa_remember']);

        // Fazer login do usuário
        Auth::login($user, session('2fa_remember', false));

        // Redirecionar baseado no tipo de usuário
        if ($user->permission == 3) {
            return redirect()->route('admin.dashboard')->with('success', "Bem vindo de volta!");
        } else {
            return redirect()->route('dashboard')->with('success', "Bem vindo de volta!");
        }
    }
}
