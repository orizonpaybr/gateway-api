<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\App;
use App\Models\User;
use App\Models\UsersKey;
use Carbon\Carbon;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, Hash, Log};
use Illuminate\View\View;
use Illuminate\Support\Str;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {

        $request->validate([
            'username' => 'required|string|regex:/^[\pL\s\'\-]+$/u|unique:users,username',
            'name' => ['required', 'string', 'max:255', 'regex:/^[\pL\s\'\-]+$/u'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'telefone' => ['required', 'string', 'unique:users,telefone'],
            'gender' => ['required', 'string', 'in:male,female'],
            'password' => [
                'required',
                'min:8',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&+#^~`|\\/:";\'<>,.=\-_\[\]{}()])[A-Za-z\d@$!%*?&+#^~`|\\/:";\'<>,.=\-_\[\]{}()]+$/',
                'confirmed'
            ],
        ], [
            'username.regex' => 'O campo nome de usuário aceita apenas letras, espaços, apóstrofos e hífens.',
            'name.regex' => 'O nome deve conter apenas letras, espaços, apóstrofos e hífens.',
            'password.regex' => 'A senha deve conter pelo menos uma letra minúscula, uma letra maiúscula, um número e um caractere especial.',
            'required' => 'O campo :attribute é obrigatório',
            'string' => ':attribute deve conter apenas letras',
            'unique' => 'O campo :attribute já está sendo utilizado',
            'email' => 'Digite um email válido',
            'min' => 'O Campo :attribute deve conter no mínimo :min caracteres',
            'max' => 'O Campo :attribute deve conter no máximo :max caracteres',
        ]);

        $senhaHash = Hash::make($request->password);

        // Gerando IDs e valores adicionais
        $clienteId = Str::uuid()->toString();
        $saldo = 0;
        $status = 0;
        $dataCadastroFormatada = Carbon::now('America/Sao_Paulo')->format('Y-m-d H:i:s');

        $indicador_ref = $request->input('ref') ?? NULL;

        // Taxas padrões (removidas - agora são gerenciadas pelo sistema de taxas personalizadas)
        $app = App::first();

        $code_ref = uniqid();

        $gerenteComMenosClientes = User::where('permission', \App\Constants\UserPermission::MANAGER)
            ->withCount('clientes') // Usando relacionamento clientes()
            ->orderBy('clientes_count', 'asc')
            ->first();
        //dd($gerenteComMenosClientes);
        if (isset($indicador_ref) && !is_null($indicador_ref)) {
            $indicador = User::where('code_ref', $indicador_ref)->first();
            if ($indicador && $indicador->permission == \App\Constants\UserPermission::MANAGER) {
                $gerenteComMenosClientes = $indicador;
            }
        }

        //dd($gerenteComMenosClientes);
        // Criando usuário
        $user = User::create([
            'username' => $request->username,
            'user_id' => $request->username,
            'name' => $request->name,
            'gender' => $request->gender,
            'email' => $request->email,
            'password' => $senhaHash,
            'telefone' => $request->telefone,
            'saldo' => $saldo,
            'data_cadastro' => $dataCadastroFormatada,
            'status' => $status,
            'cliente_id' => $clienteId,
            'code_ref' => $code_ref,
            'indicador_ref' => $indicador_ref,
            'gerente_id' => $gerenteComMenosClientes->id ?? NULL,
            'gerente_percentage' => $gerenteComMenosClientes->gerente_percentage ?? 0.00,
            'avatar' => "/uploads/avatars/avatar_default.jpg"
        ]);

        $token = Str::uuid()->toString();
        $secret = Str::uuid()->toString();
        $user_id = $user->user_id;

        UsersKey::create(compact('user_id', 'token', 'secret'));

        // PROCESSAR AFILIADO SE houver parâmetro 'ref' na URL
        $affiliateCode = $request->get('ref'); 
        $affiliateUser = null;
        if ($affiliateCode) {
            $affiliateUser = User::where('affiliate_code', $affiliateCode)
                ->where('is_affiliate', true)
                ->where('affiliate_percentage', '>', 0)
                ->first();
                
            if ($affiliateUser) {
                // Atualizar usuário com dados do affiliate
                $user->update([
                    'affiliate_id' => $affiliateUser->id,
                    'affiliate_percentage' => $affiliateUser->affiliate_percentage
                ]);
                
                Log::info('[REGISTRO AFFILIATE] Usuário registrado via affiliate', [
                    'novo_usuario_id' => $user->id,
                    'affiliate_id' => $affiliateUser->id,
                    'affiliate_code' => $affiliateCode,
                    'affiliate_percentage' => $affiliateUser->affiliate_percentage
                ]);
            }
        }

        // Criar split interno automático se usuário tem gerente configurado
        if ($gerenteComMenosClientes && $gerenteComMenosClientes->gerente_percentage > 0) {
            try {
                \App\Models\SplitInterno::create([
                    'usuario_pagador_id' => $user->id,
                    'usuario_beneficiario_id' => $gerenteComMenosClientes->id,
                    'porcentagem_split' => $gerenteComMenosClientes->gerente_percentage,
                    'tipo_taxa' => \App\Models\SplitInterno::TAXA_DEPOSITO,
                    'ativo' => true,
                    'criado_por_admin_id' => 1, // Sistema automático
                    'data_inicio' => now(),
                    'data_fim' => null,
                ]);
                
                Log::info('[REGISTRO AUTOMATICO] Split interno criado para novo usuário', [
                    'novo_usuario_id' => $user->id,
                    'gerente_id' => $gerenteComMenosClientes->id,
                    'gerente_percentage' => $gerenteComMenosClientes->gerente_percentage
                ]);
            } catch (\Exception $e) {
                Log::error('[REGISTRO AUTOMATICO] Erro ao criar split interno', [
                    'erro' => $e->getMessage(),
                    'novo_usuario_id' => $user->id,
                    'gerente_id' => $gerenteComMenosClientes->id ?? null
                ]);
            }
        }

        // Criar split interno automático se usuário foi indicado por affiliate
        if ($affiliateUser && $affiliateUser->isAffiliateAtivo()) {
            try {
                \App\Models\SplitInterno::create([
                    'usuario_pagador_id' => $user->id,
                    'usuario_beneficiario_id' => $affiliateUser->id,
                    'porcentagem_split' => $affiliateUser->affiliate_percentage,
                    'tipo_taxa' => \App\Models\SplitInterno::TAXA_DEPOSITO,
                    'ativo' => true,
                    'criado_por_admin_id' => 1, // Sistema automático
                    'data_inicio' => now(),
                    'data_fim' => null,
                ]);
                
                Log::info('[REGISTRO AFFILIATE] Split interno automático criado', [
                    'novo_usuario_id' => $user->id,
                    'affiliate_id' => $affiliateUser->id,
                    'affiliate_percentage' => $affiliateUser->affiliate_percentage
                ]);
            } catch (\Exception $e) {
                Log::error('[REGISTRO AFFILIATE] Erro ao criar split interno', [
                    'erro' => $e->getMessage(),
                    'novo_usuario_id' => $user->id,
                    'affiliate_id' => $affiliateUser->id ?? null
                ]);
            }
        }

        event(new Registered($user));

        Auth::login($user);

        return redirect(route('dashboard', absolute: false));
    }
}
