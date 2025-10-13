<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UsersKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use PragmaRX\Google2FA\Google2FA;

class AuthController extends Controller
{
    /**
     * Login do usuário via API
     */
    public function login(Request $request)
    {
        try {
            // Headers CORS para permitir requisições do app mobile
            $response = response();
            
            // Validar dados de entrada
            $validator = Validator::make($request->all(), [
                'username' => 'required|string',
                'password' => 'required|string',
            ]);

            if ($validator->fails()) {
                return $response->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 400)->header('Access-Control-Allow-Origin', '*')
                  ->header('Access-Control-Allow-Methods', 'POST, GET, OPTIONS')
                  ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
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

            // Verificar se o usuário tem 2FA ativo (PIN-based)
            if ($user->twofa_enabled && $user->twofa_pin) {
                // Gerar token temporário para verificação 2FA
                $tempToken = base64_encode(json_encode([
                    'user_id' => $user->username,
                    'temp' => true,
                    'expires_at' => now()->addMinutes(5)->timestamp
                ]));

                Log::info('Login requer verificação 2FA', [
                    'username' => $username,
                    'ip' => $request->ip()
                ]);

                return $response->json([
                    'success' => false,
                    'requires_2fa' => true,
                    'message' => 'Digite o código de 6 dígitos do seu app autenticador',
                    'temp_token' => $tempToken
                ], 200)->header('Access-Control-Allow-Origin', '*')
                  ->header('Access-Control-Allow-Methods', 'POST, GET, OPTIONS')
                  ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
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

            // Gerar token JWT simples (você pode usar uma biblioteca JWT real)
            $token = base64_encode(json_encode([
                'user_id' => $user->username,
                'token' => $userKeys->token,
                'secret' => $userKeys->secret,
                'expires_at' => now()->addHours(24)->timestamp
            ]));

            Log::info('Login bem-sucedido via API', [
                'username' => $username,
                'ip' => $request->ip()
            ]);

            return $response->json([
                'success' => true,
                'message' => 'Login realizado com sucesso',
                'data' => [
                    'user' => [
                        'id' => $user->username,
                        'username' => $user->username,
                        'email' => $user->email ?? '',
                        'name' => $user->name ?? $user->username,
                    ],
                    'token' => $token,
                    'api_token' => $userKeys->token,
                    'api_secret' => $userKeys->secret,
                ]
            ])->header('Access-Control-Allow-Origin', '*')
              ->header('Access-Control-Allow-Methods', 'POST, GET, OPTIONS')
              ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');

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
                ], 400)->header('Access-Control-Allow-Origin', '*');
            }

            $tempToken = $request->input('temp_token');
            $code = $request->input('code');

            // Decodificar token temporário
            $decoded = json_decode(base64_decode($tempToken), true);
            
            if (!$decoded || !isset($decoded['temp']) || !$decoded['temp'] || 
                !isset($decoded['expires_at']) || $decoded['expires_at'] < now()->timestamp) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token temporário expirado ou inválido'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }

            // Buscar usuário
            $user = User::where('username', $decoded['user_id'])->first();
            
            if (!$user || !$user->twofa_enabled || !$user->twofa_pin) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não encontrado ou 2FA não configurado'
                ], 401)->header('Access-Control-Allow-Origin', '*');
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
                ], 400)->header('Access-Control-Allow-Origin', '*');
            }

            // Buscar as chaves do usuário
            $userKeys = UsersKey::where('user_id', $user->username)->first();

            if (!$userKeys) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário sem chaves de API configuradas'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }

            // Gerar token final
            $token = base64_encode(json_encode([
                'user_id' => $user->username,
                'token' => $userKeys->token,
                'secret' => $userKeys->secret,
                'expires_at' => now()->addHours(24)->timestamp
            ]));

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
                    ],
                    'token' => $token,
                    'api_token' => $userKeys->token,
                    'api_secret' => $userKeys->secret,
                ]
            ])->header('Access-Control-Allow-Origin', '*')
              ->header('Access-Control-Allow-Methods', 'POST, GET, OPTIONS')
              ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');

        } catch (\Exception $e) {
            Log::error('Erro na verificação 2FA da API', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500)->header('Access-Control-Allow-Origin', '*');
        }
    }

    /**
     * Verificar token válido
     */
    public function verifyToken(Request $request)
    {
        try {
            $token = $request->bearerToken();
            
            if (!$token) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token não fornecido'
                ], 401);
            }

            // Decodificar token
            $decoded = json_decode(base64_decode($token), true);
            
            if (!$decoded || !isset($decoded['expires_at']) || $decoded['expires_at'] < now()->timestamp) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token expirado'
                ], 401);
            }

            // Buscar usuário
            $user = User::where('username', $decoded['user_id'])->first();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não encontrado'
                ], 401);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $user->username,
                        'username' => $user->username,
                        'email' => $user->email ?? '',
                        'name' => $user->name ?? $user->username,
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
        // Com token simples, não há muito o que fazer no logout
        // Em uma implementação JWT real, você invalidaria o token
        
        return response()->json([
            'success' => true,
            'message' => 'Logout realizado com sucesso'
        ])->header('Access-Control-Allow-Origin', '*')
          ->header('Access-Control-Allow-Methods', 'POST, GET, OPTIONS')
          ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
    }

    /**
     * Registro de novo usuário via API
     */
    public function register(Request $request)
    {
        try {
            // Validar dados de entrada
            $validator = Validator::make($request->all(), [
                'username' => 'required|string|regex:/^[\pL\pN\s\'\-]+$/u|unique:users,username',
                'name' => 'required|string|max:255|regex:/^[\pL\s\'\-]+$/u',
                'email' => 'required|string|lowercase|email|max:255|unique:users,email',
                'telefone' => 'required|string|unique:users,telefone',
                'cpf_cnpj' => 'required|string|unique:users,cpf_cnpj',
                'password' => [
                    'required',
                    'min:8',
                    'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&+#^~`|\\/:";\'<>,.=\-_\[\]{}()])[A-Za-z\d@$!%*?&+#^~`|\\/:";\'<>,.=\-_\[\]{}()]+$/',
                ],
                'documentoFrente' => 'nullable|file|mimes:jpeg,jpg,png,pdf|max:5120',
                'documentoVerso' => 'nullable|file|mimes:jpeg,jpg,png,pdf|max:5120',
                'selfieDocumento' => 'nullable|file|mimes:jpeg,jpg,png,pdf|max:5120',
            ], [
                'username.regex' => 'O campo nome de usuário aceita apenas letras, números, espaços, apóstrofos e hífens.',
                'name.regex' => 'O nome deve conter apenas letras, espaços, apóstrofos e hífens.',
                'password.regex' => 'A senha deve conter pelo menos uma letra minúscula, uma letra maiúscula, um número e um caractere especial.',
                'username.unique' => 'Este nome de usuário já está em uso.',
                'email.unique' => 'Este email já está em uso.',
                'telefone.unique' => 'Este telefone já está em uso.',
                'cpf_cnpj.unique' => 'Este CPF/CNPJ já está em uso.',
                'cpf_cnpj.required' => 'O CPF/CNPJ é obrigatório.',
                'documentoFrente.mimes' => 'O documento deve ser uma imagem (JPEG, JPG, PNG) ou PDF.',
                'documentoFrente.max' => 'O documento não pode exceder 5MB.',
                'documentoVerso.mimes' => 'O documento deve ser uma imagem (JPEG, JPG, PNG) ou PDF.',
                'documentoVerso.max' => 'O documento não pode exceder 5MB.',
                'selfieDocumento.mimes' => 'A selfie deve ser uma imagem (JPEG, JPG, PNG) ou PDF.',
                'selfieDocumento.max' => 'A selfie não pode exceder 5MB.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 400)->header('Access-Control-Allow-Origin', '*')
                  ->header('Access-Control-Allow-Methods', 'POST, GET, OPTIONS')
                  ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
            }

            $senhaHash = Hash::make($request->password);

            // Gerando IDs e valores adicionais
            $clienteId = \Illuminate\Support\Str::uuid()->toString();
            $saldo = 0;
            $status = 5; // Status 5 = Pendente de Aprovação pelo Admin
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

            $app = \App\Models\App::first();
            $code_ref = uniqid();

            $gerenteComMenosClientes = User::where('permission', 5)
                ->withCount('clientes')
                ->orderBy('clientes_count', 'asc')
                ->first();

            if (isset($indicador_ref) && !is_null($indicador_ref)) {
                $indicador = User::where('code_ref', $indicador_ref)->first();
                if ($indicador && $indicador->permission == 5) {
                    $gerenteComMenosClientes = $indicador;
                }
            }

            // Criando usuário
            $user = User::create([
                'username' => $request->username,
                'user_id' => $request->username,
                'name' => $request->name,
                'email' => $request->email,
                'password' => $senhaHash,
                'telefone' => $request->telefone,
                'cpf_cnpj' => $request->cpf_cnpj,
                'saldo' => $saldo,
                'data_cadastro' => $dataCadastroFormatada,
                'status' => $status,
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
            $token = \Illuminate\Support\Str::uuid()->toString();
            $secret = \Illuminate\Support\Str::uuid()->toString();
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

            // Gerar token de autenticação
            $authToken = base64_encode(json_encode([
                'user_id' => $user->username,
                'token' => $userKeys->token,
                'secret' => $userKeys->secret,
                'expires_at' => now()->addHours(24)->timestamp
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Cadastro realizado com sucesso! Sua conta está pendente de aprovação pelo administrador.',
                'data' => [
                    'user' => [
                        'id' => $user->username,
                        'username' => $user->username,
                        'email' => $user->email,
                        'name' => $user->name,
                        'status' => $user->status,
                        'status_text' => 'Pendente de Aprovação'
                    ],
                    'token' => $authToken,
                    'api_token' => $userKeys->token,
                    'api_secret' => $userKeys->secret,
                    'pending_approval' => true
                ]
            ], 201)->header('Access-Control-Allow-Origin', '*')
              ->header('Access-Control-Allow-Methods', 'POST, GET, OPTIONS')
              ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');

        } catch (\Exception $e) {
            Log::error('Erro no registro via API', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500)->header('Access-Control-Allow-Origin', '*');
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
                ], 422)->header('Access-Control-Allow-Origin', '*')
                  ->header('Access-Control-Allow-Methods', 'POST, GET, OPTIONS')
                  ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
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
            ])->header('Access-Control-Allow-Origin', '*')
              ->header('Access-Control-Allow-Methods', 'POST, GET, OPTIONS')
              ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');

        } catch (\Exception $e) {
            Log::error('Erro na validação de dados únicos', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500)->header('Access-Control-Allow-Origin', '*');
        }
    }
}
