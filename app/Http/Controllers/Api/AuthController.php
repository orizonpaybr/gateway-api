<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\RegisterUserRequest;
use App\Models\User;
use App\Models\UsersKey;
use App\Constants\UserStatus;
use App\Helpers\{UserStatusHelper, AppSettingsHelper};
use App\Services\JWTService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use PragmaRX\Google2FA\Google2FA;

class AuthController extends Controller
{
    private JWTService $jwtService;
    
    public function __construct(JWTService $jwtService)
    {
        $this->jwtService = $jwtService;
    }
    
    /**
     * Login do usuário via API
     */
    public function login(Request $request)
    {
        try {
            // Validar dados de entrada
            $validator = Validator::make($request->all(), [
                'username' => 'required|string',
                'password' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 400);
            }

            $username = $request->input('username');
            $password = $request->input('password');

            // Buscar usuário pelo username ou email
            $user = User::where('username', $username)
                       ->orWhere('email', $username)
                       ->first();

            if (!$user) {
                Log::warning('Tentativa de login com usuário inexistente', [
                    'username' => $username,
                    'ip' => $request->ip()
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não encontrado'
                ], 401);
            }

            // Verificar senha
            if (!Hash::check($password, $user->password)) {
                Log::warning('Tentativa de login com senha incorreta', [
                    'username' => $username,
                    'ip' => $request->ip()
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Senha incorreta'
                ], 401);
            }

            // Verificar se usuário pode fazer login
            if (!UserStatusHelper::canLogin($user)) {
                Log::warning('Tentativa de login com conta inativa/banida', [
                    'username' => $username,
                    'status' => $user->status,
                    'banido' => $user->banido,
                    'ip' => $request->ip()
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Sua conta foi desativada ou bloqueada. Entre em contato com o suporte.'
                ], 403);
            }

            // Verificar se o usuário tem 2FA ativo (PIN-based)
            if ($user->twofa_enabled && $user->twofa_pin) {
                // Gerar token temporário para verificação 2FA (usando JWT real)
                $tempToken = $this->jwtService->generate2FAToken($user->username);

                Log::info('Login requer verificação 2FA', [
                    'username' => $username,
                    'ip' => $request->ip()
                ]);

                return response()->json([
                    'success' => false,
                    'requires_2fa' => true,
                    'message' => 'Digite o código de 6 dígitos do seu app autenticador',
                    'temp_token' => $tempToken
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

            Log::info('Login bem-sucedido via API', [
                'username' => $username,
                'ip' => $request->ip()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Login realizado com sucesso',
                'data' => [
                    'user' => [
                        'id' => $user->username,
                        'username' => $user->username,
                        'email' => $user->email ?? '',
                        'name' => $user->name ?? $user->username,
                        'gender' => $user->gender ?? null,
                        'permission' => $user->permission ?? null,
                        'status' => $user->status ?? null,
                    ],
                    'token' => $token,
                    'api_token' => $userKeys->token,
                    'api_secret' => $userKeys->secret,
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
    public function verify2FA(Request $request)
    {
        try {
            // Validar dados de entrada
            $validator = Validator::make($request->all(), [
                'temp_token' => 'required|string',
                'code' => 'required|string|size:6'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 400);
            }

            $tempToken = $request->input('temp_token');
            $code = $request->input('code');

            // Validar token temporário usando JWT real
            $decoded = $this->jwtService->validateToken($tempToken);
            
            if (!$decoded) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token temporário expirado ou inválido'
                ], 401);
            }
            
            // Verificar se é realmente um token temporário para 2FA
            if (!isset($decoded->temp) || $decoded->temp !== true || 
                !isset($decoded->purpose) || $decoded->purpose !== '2fa_verification') {
                return response()->json([
                    'success' => false,
                    'message' => 'Token temporário inválido'
                ], 401);
            }

            // Buscar usuário
            $user = User::where('username', $decoded->sub)->first();
            
            if (!$user || !$user->twofa_enabled || !$user->twofa_pin) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não encontrado ou 2FA não configurado'
                ], 401);
            }

            // Verificar se usuário pode fazer login
            if (!UserStatusHelper::canLogin($user)) {
                Log::warning('Tentativa de login 2FA com conta inativa/banida', [
                    'username' => $user->username,
                    'status' => $user->status,
                    'banido' => $user->banido,
                    'ip' => $request->ip()
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Sua conta foi desativada ou bloqueada. Entre em contato com o suporte.'
                ], 403);
            }

            // Verificar PIN 2FA
            $valid = Hash::check($code, $user->twofa_pin);

            if (!$valid) {
                Log::warning('Código 2FA inválido', [
                    'username' => $user->username,
                    'ip' => $request->ip()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Código inválido'
                ], 400);
            }

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

            return response()->json([
                'success' => true,
                'message' => 'Login realizado com sucesso',
                'data' => [
                    'user' => [
                        'id' => $user->username,
                        'username' => $user->username,
                        'email' => $user->email ?? '',
                        'name' => $user->name ?? $user->username,
                        'gender' => $user->gender ?? null,
                        'permission' => $user->permission ?? null,
                        'status' => $user->status ?? null,
                    ],
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
                Log::warning('Tentativa de verificar token com conta inativa/banida', [
                    'username' => $user->username,
                    'status' => $user->status,
                    'banido' => $user->banido,
                    'ip' => $request->ip()
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Sua conta foi desativada ou bloqueada. Entre em contato com o suporte.'
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
        // Com JWT stateless, não há como invalidar o token no servidor
        // Para uma implementação completa, seria necessário uma blacklist em cache/banco
        // Por enquanto, o frontend deve remover o token localmente
        
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

            $senhaHash = Hash::make($request->password);

            // Gerando IDs e valores adicionais
            $clienteId = \Illuminate\Support\Str::uuid()->toString();
            $saldo = 0;
            $status = UserStatus::PENDING; // Status pendente - aguardando aprovação do admin
            $dataCadastroFormatada = \Carbon\Carbon::now('America/Sao_Paulo')->format('Y-m-d H:i:s');
            
            // Processar upload de documentos
            $fotoRgFrente = null;
            $fotoRgVerso = null;
            $selfieRg = null;
            
            if ($request->hasFile('documentoFrente')) {
                $file = $request->file('documentoFrente');
                $filename = 'doc_frente_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $file->storeAs('uploads/documentos', $filename, 'public');
                $fotoRgFrente = '/storage/uploads/documentos/' . $filename;
            }
            
            if ($request->hasFile('documentoVerso')) {
                $file = $request->file('documentoVerso');
                $filename = 'doc_verso_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $file->storeAs('uploads/documentos', $filename, 'public');
                $fotoRgVerso = '/storage/uploads/documentos/' . $filename;
            }
            
            if ($request->hasFile('selfieDocumento')) {
                $file = $request->file('selfieDocumento');
                $filename = 'selfie_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $file->storeAs('uploads/documentos', $filename, 'public');
                $selfieRg = '/storage/uploads/documentos/' . $filename;
            }

            $indicador_ref = $request->input('ref') ?? NULL;

            // Não é necessário buscar App aqui, apenas gerar code_ref
            $code_ref = uniqid();

            $gerenteComMenosClientes = null;
            try {
                $gerenteComMenosClientes = User::where('permission', \App\Constants\UserPermission::MANAGER)
                    ->withCount('clientes')
                    ->orderBy('clientes_count', 'asc')
                    ->first();
            } catch (\Exception $e) {
                Log::warning('Erro ao buscar gerente com withCount, tentando sem', [
                    'error' => $e->getMessage()
                ]);
                $gerenteComMenosClientes = User::where('permission', \App\Constants\UserPermission::MANAGER)
                    ->first();
            }

            if (isset($indicador_ref) && !is_null($indicador_ref)) {
                $indicador = User::where('code_ref', $indicador_ref)->first();
                // Se o indicador for gerente (permission = 2), usar ele como gerente
                if ($indicador && $indicador->permission == \App\Constants\UserPermission::MANAGER) {
                    $gerenteComMenosClientes = $indicador;
                }
            }

            // Criando usuário
            $user = User::create([
                'username' => $request->username,
                'user_id' => $request->username,
                'name' => $request->name,
                'gender' => $request->gender,
                'email' => $request->email,
                'password' => $senhaHash,
                'telefone' => $request->telefone,
                'cpf_cnpj' => $request->cpf_cnpj,
                'saldo' => $saldo,
                'data_cadastro' => $dataCadastroFormatada,
                'status' => $status,
                'permission' => \App\Constants\UserPermission::CLIENT, // Sempre CLIENT (1) no cadastro
                'cliente_id' => $clienteId,
                'code_ref' => $code_ref,
                'indicador_ref' => $indicador_ref,
                'gerente_id' => $gerenteComMenosClientes->id ?? NULL,
                'gerente_percentage' => $gerenteComMenosClientes->gerente_percentage ?? 0.00,
                'avatar' => "/uploads/avatars/avatar_default.jpg",
                'foto_rg_frente' => $fotoRgFrente,
                'foto_rg_verso' => $fotoRgVerso,
                'selfie_rg' => $selfieRg,
            ]);

            // Criar chaves de API para o usuário
            $apiToken = \Illuminate\Support\Str::uuid()->toString();
            $apiSecret = \Illuminate\Support\Str::uuid()->toString();
            $user_id = $user->user_id;

            UsersKey::create([
                'user_id' => $user_id,
                'token' => $apiToken,
                'secret' => $apiSecret,
                'status' => 'active' // Campo obrigatório na tabela users_key
            ]);

            // PROCESSAR AFILIADO SE houver parâmetro 'ref' na URL
            $affiliateCode = $request->get('ref'); 
            $affiliateUser = null;
            if ($affiliateCode) {
                $affiliateUser = User::where('affiliate_code', $affiliateCode)
                    ->where('is_affiliate', true)
                    ->where('affiliate_percentage', '>', 0)
                    ->first();
                    
                if ($affiliateUser) {
                    $user->update([
                        'affiliate_id' => $affiliateUser->id,
                        'affiliate_percentage' => $affiliateUser->affiliate_percentage
                    ]);
                    
                    Log::info('[REGISTRO AFFILIATE API] Usuário registrado via affiliate', [
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
                        'criado_por_admin_id' => 1,
                        'data_inicio' => now(),
                        'data_fim' => null,
                    ]);
                } catch (\Exception $e) {
                    Log::error('[REGISTRO AUTOMATICO API] Erro ao criar split interno', [
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
                        'criado_por_admin_id' => 1,
                        'data_inicio' => now(),
                        'data_fim' => null,
                    ]);
                } catch (\Exception $e) {
                    Log::error('[REGISTRO AFFILIATE API] Erro ao criar split interno', [
                        'erro' => $e->getMessage(),
                        'novo_usuario_id' => $user->id,
                        'affiliate_id' => $affiliateUser->id ?? null
                    ]);
                }
            }

            Log::info('Usuário registrado com sucesso via API', [
                'username' => $request->username,
                'ip' => $request->ip(),
                'status' => 'pendente_aprovacao'
            ]);

            // Buscar as chaves criadas
            $userKeys = UsersKey::where('user_id', $user->user_id)->first();

            // Gerar token JWT de autenticação (sem dados sensíveis!)
            $authToken = $this->jwtService->generateToken($user->username, [
                'permission' => $user->permission,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Cadastro realizado com sucesso! Sua conta está pendente de aprovação pelo administrador.',
                'data' => [
                    'user' => [
                        'id' => $user->username,
                        'username' => $user->username,
                        'email' => $user->email,
                        'name' => $user->name,
                        'gender' => $user->gender,
                        'status' => $user->status,
                        'status_text' => 'Pendente de Aprovação'
                    ],
                    'token' => $authToken,
                    'api_token' => $userKeys->token,
                    'api_secret' => $userKeys->secret,
                    'pending_approval' => true
                ]
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
                ]
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor',
                'error' => app()->environment('local') ? $e->getMessage() : null
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
}
