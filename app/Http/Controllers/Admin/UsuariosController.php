<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Constants\UserStatus;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\UsersKey;
use App\Models\Adquirente;
use Carbon\Carbon;
use Illuminate\Support\Facades\{Hash, Log, Auth};

class UsuariosController extends Controller
{
    public function index(Request $request)
    {
        $users = User::where('id', '>=', 1); // cria query base

        $status = $request->query('status');
        $buscar = $request->query('buscar');

        switch ($status) {
            case 'ativos':
                $users->where('banido', 0);
                break;
            case 'banidos':
                $users->where('banido', 1);
                break;
            case 'pendentes':
                $users->where('status', UserStatus::PENDING);
                break;
        }
        if (isset($buscar)) {
            $users->where('name', "LIKE", "%$buscar%");
        }

        $users = $users->get(); // executa a query e pega os resultados
        $gerentes = User::where('permission', \App\Constants\UserPermission::MANAGER)->get();
        $adquirentes = Adquirente::all();
        return view('admin.usuarios', compact('users', 'gerentes', 'adquirentes'));
    }

    public function detalhes($id, Request $request)
    {
        // Obter a data e hora atual usando Carbon
        $now = Carbon::now();

        // Início e fim do dia de hoje
        $todayStart = $now->copy()->startOfDay()->toDateTimeString();
        $todayEnd = $now->copy()->endOfDay()->toDateTimeString();

        // Início do mês
        $startOfMonth = $now->copy()->startOfMonth()->toDateTimeString();

        // Início da semana
        $startOfWeek = $now->copy()->startOfWeek()->toDateTimeString();

        // Consultas para obter os totais
        $totalCadastros = User::count();

        $cadastrosHoje = User::whereBetween('data_cadastro', [$todayStart, $todayEnd])
            ->count();

        $cadastrosMes = User::where('data_cadastro', '>=', $startOfMonth)
            ->count();

        $cadastrosSemana = User::where('data_cadastro', '>=', $startOfWeek)
            ->count();

        $usuario = User::find($id);
        return view('admin.usuariodetalhes', compact('usuario'));
    }

    public function usuarioStatus(Request $request)
    {
        $message = "";
        $usuarioId = $request->input('id');
        $usuario = User::where('id', $usuarioId)->first();

        if ($request->tipo === 'status') {
            // Alternar entre ACTIVE (1) e PENDING (2)
            // Se estiver pendente (2 ou 5 para compatibilidade), aprovar (1)
            // Se estiver aprovado (1), tornar pendente (2)
            $status = ($usuario->status == UserStatus::ACTIVE || $usuario->status == 1) ? UserStatus::PENDING : UserStatus::ACTIVE;
            $message = $status == UserStatus::PENDING ? "Status alterado para pendente!" : "Status alterado para Aprovado";
            $usuario->update(['status' => $status]);
        }

        if ($request->tipo === 'banido') {
            $banido = $usuario->banido == 1 ? 0 : 1;
            $message = $usuario->banido == 1 ? "Usuário desbanido com sucesso!" : "Usuário banido com sucesso!";
            $usuario->update(['banido' => $banido]);
        }

        return redirect()->back()->with('success', $message);
    }

    public function destroy($id, Request $request)
    {
        $user = User::find($id);
        if (!$user) {
            return redirect()->back()->with('error', "Usuário não encontrado!");
        }

        $user->delete();
        return redirect()->route('admin.usuarios')->with('success', "Usuário removido com sucesso!");
    }

    public function edit($id, Request $request)
    {
        if (!isset($id)) {
            return redirect()->back()->with('error', "Selecione um usuário!");
        }

        $email = $request->input('email');
        $name = $request->input('name');
        $permission = $request->input('permission');
        $cpf_cnpj = $request->input('cpf_cnpj');
        $telefone = $request->input('telefone');
        $data_nascimento = $request->input('data_nascimento');

        // Debug: Log dos dados recebidos
        Log::info('Dados recebidos na edição:', [
            'cpf_cnpj' => $cpf_cnpj,
            'telefone' => $telefone,
            'data_nascimento' => $data_nascimento,
            'all_input' => $request->all()
        ]);


        $token = $request->input('token');
        $secret = $request->input('secret');


        $user = User::find($id);

        if (!isset($user)) {
            return redirect()->back()->with('error', "Usuário não encontrado!");
        }

        // Só valida CPF se estiver sendo alterado e não for apenas alteração de senha
        if ($user->cpf_cnpj != $cpf_cnpj && !empty($cpf_cnpj)) {
            try {
                $validation = $request->validate([
                    'cpf_cnpj' => ['unique:users,cpf_cnpj,' . $id],
                ]);
                Log::info('Validação CPF/CNPJ passou:', ['cpf_cnpj' => $cpf_cnpj]);
            } catch (\Illuminate\Validation\ValidationException $e) {
                Log::error('Erro na validação CPF/CNPJ:', [
                    'cpf_cnpj' => $cpf_cnpj,
                    'errors' => $e->errors()
                ]);
                return redirect()->back()->with('error', "CPF já cadastrado na base!");
            }
        }

        // Só valida email se estiver sendo alterado e não for apenas alteração de senha
        if ($user->email != $email && !empty($email)) {
            try {
                $validation = $request->validate([
                    'email' => ['unique:users,email,' . $id],
                ]);
                Log::info('Validação email passou:', ['email' => $email]);
            } catch (\Illuminate\Validation\ValidationException $e) {
                Log::error('Erro na validação email:', [
                    'email' => $email,
                    'errors' => $e->errors()
                ]);
                return redirect()->back()->with('error', "Email já cadastrado na base!");
            }
        }

        $payl = [
            'email' => $email,
            'name' => $name,
            'permission' => $permission,
            'cpf_cnpj' => $cpf_cnpj,
            'data_nascimento' => $data_nascimento,
            'telefone' => $telefone
        ];

        $path = uniqid();
        if ($request->hasFile('foto_rg_frente')) {
            $fotoRgFrente = Helper::salvarArquivo($request, 'foto_rg_frente', $path);
            $payl['foto_rg_frente'] = $fotoRgFrente;
        }

        if ($request->hasFile('foto_rg_verso')) {
            $fotoRgVerso  = Helper::salvarArquivo($request, 'foto_rg_verso', $path);
            $payl['foto_rg_verso'] = $fotoRgVerso;
        }

        if ($request->hasFile('selfie_rg')) {
            $selfieRg     = Helper::salvarArquivo($request, 'selfie_rg', $path);
            $payl['selfie_rg'] = $selfieRg;
        }

        if (!is_null($request->password)) {
            // Validar nova senha com requisitos robustos
            $request->validate([
                'password' => [
                    'required',
                    'min:8',
                    'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&+#^~`|\\/:";\'<>,.=\-_\[\]{}()])[A-Za-z\d@$!%*?&+#^~`|\\/:";\'<>,.=\-_\[\]{}()]+$/',
                ],
            ], [
                'password.required' => 'A nova senha é obrigatória.',
                'password.min' => 'A nova senha deve ter pelo menos 8 caracteres.',
                'password.regex' => 'A nova senha deve conter pelo menos uma letra minúscula, uma letra maiúscula, um número e um caractere especial.',
            ]);
            
            $payl['password'] = Hash::make($request->input('password'));
        }

        if ($request->filled('gerente_percentage')) {
            $valorFormatado = $request->input('gerente_percentage');
            $valorLimpo = str_replace(['.', ','], ['', '.'], $valorFormatado);
            $payl['gerente_percentage'] = (float) $valorLimpo;
        } else {
            $payl['gerente_percentage'] = 0.00;
        }

        if ($request->filled('gerente_id')) {
            $payl['gerente_id'] = $request->input('gerente_id');
            
            // Automatizar criação de split interno se gerente e porcentagem configurados
            $this->criarSplitAutomaticoGerente($request->input('gerente_id'), $payl['gerente_percentage'], $id);
        }

        $payl['gerente_aprovar'] = $request->has('gerente_aprovar');
        
        // PROCESSAR CONFIGURAÇÕES DE AFFILIATE
        // IMPORTANTE: Só processar se os campos de afiliado estiverem presentes no request
        // para evitar desativar afiliados quando o admin edita outras abas
        if ($request->has('affiliate_percentage') || $request->has('is_affiliate')) {
            $payl['is_affiliate'] = $request->has('is_affiliate');
            $payl['affiliate_percentage'] = $request->has('is_affiliate') && $request->filled('affiliate_percentage') 
                ? (float)$request->input('affiliate_percentage') 
                : 0.00;
                
            // Gerar código e link de affiliate se ativado
            if ($payl['is_affiliate'] && $payl['affiliate_percentage'] > 0) {
                $user = \App\Models\User::find($id);
                if ($user) {
                    $this->processarAffiliateSettings($user, $request);
                }
            }
            
            Log::info('[ADMIN EDIT] Configurações de afiliado processadas', [
                'user_id' => $id,
                'is_affiliate' => $payl['is_affiliate'],
                'affiliate_percentage' => $payl['affiliate_percentage'] ?? 0
            ]);
        } else {
            Log::info('[ADMIN EDIT] Campos de afiliado não presentes no request - mantendo configuração atual', [
                'user_id' => $id
            ]);
        }
        
        // Sistema de taxas personalizadas removido - usar apenas configurações globais
        
        // Processar adquirente específica para PIX
        $preferredAdquirente = $request->input('preferred_adquirente');
        if ($preferredAdquirente) {
            $payl['preferred_adquirente'] = $preferredAdquirente;
            $payl['adquirente_override'] = true;
        } else {
            $payl['preferred_adquirente'] = null;
            $payl['adquirente_override'] = false;
        }

        // Processar adquirente específica para Cartão+Boleto
        $preferredAdquirenteCard = $request->input('preferred_adquirente_card_billet');
        if ($preferredAdquirenteCard) {
            $payl['preferred_adquirente_card_billet'] = $preferredAdquirenteCard;
            $payl['adquirente_card_billet_override'] = true;
        } else {
            $payl['preferred_adquirente_card_billet'] = null;
            $payl['adquirente_card_billet_override'] = false;
        }

        // Debug: Log final do payload antes da atualização
        Log::info('Payload final antes da atualização:', $payl);
        
        User::where('id', $id)->update($payl);

        $userkey = UsersKey::where('user_id', $user->user_id)->first();
        if (!$userkey) {
            $user_id = $user->user_id;
            $userkey = UsersKey::create(compact('user_id', 'token', 'secret'));
        }

        $userkey->update(compact('token', 'secret'));

        return redirect()->back()->with('success', "Usuário alterado com sucesso!");
    }

    public function changePassword($id, Request $request)
    {
        if (!isset($id)) {
            return redirect()->back()->with('error', "Selecione um usuário!");
        }

        $user = User::find($id);

        if (!isset($user)) {
            return redirect()->back()->with('error', "Usuário não encontrado!");
        }

        // Validar nova senha com requisitos robustos
        $request->validate([
            'password' => [
                'required',
                'min:8',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&+#^~`|\\/:";\'<>,.=\-_\[\]{}()])[A-Za-z\d@$!%*?&+#^~`|\\/:";\'<>,.=\-_\[\]{}()]+$/',
            ],
        ], [
            'password.required' => 'A nova senha é obrigatória.',
            'password.min' => 'A nova senha deve ter pelo menos 8 caracteres.',
            'password.regex' => 'A nova senha deve conter pelo menos uma letra minúscula, uma letra maiúscula, um número e um caractere especial.',
        ]);

        // Atualizar apenas a senha
        $user->update([
            'password' => Hash::make($request->input('password'))
        ]);

        return redirect()->back()->with('success', "Senha alterada com sucesso!");
    }

    /**
     * Cria automaticamente split interno para gerente quando usuário é configurado
     * 
     * @param int $gerenteId
     * @param float $gerentePercentage  
     * @param int $userId
     */
    private function criarSplitAutomaticoGerente(int $gerenteId, float $gerentePercentage, int $userId): void
    {
        try {
            // Verificar se já existe split automático configurado para este gerente/usuario
            $splitExistente = \App\Models\SplitInterno::query()
                ->where('usuario_pagador_id', $userId)
                ->where('usuario_beneficiario_id', $gerenteId)
                ->where('porcentagem_split', $gerentePercentage)
                ->ativos()
                ->first();

            if ($splitExistente) {
                Log::info('[AUTO SPLIT GERENTE] Split já existe para este usuário/gerente', [
                    'user_id' => $userId,
                    'gerente_id' => $gerenteId,
                    'split_id' => $splitExistente->id,
                    'percentage' => $gerentePercentage
                ]);
                return;
            }

            // Criar configuração de split automática para o gerente
            $novoSplit = \App\Models\SplitInterno::create([
                'usuario_pagador_id' => $userId,
                'usuario_beneficiario_id' => $gerenteId,
                'porcentagem_split' => $gerentePercentage,
                'tipo_taxa' => \App\Models\SplitInterno::TAXA_DEPOSITO,
                'ativo' => true,
                'criado_por_admin_id' => Auth::id() ?? 1,
                'data_inicio' => now(),
                'data_fim' => null,
            ]);

            Log::info('[AUTO SPLIT GERENTE] Split automático criado', [
                'split_id' => $novoSplit->id,
                'user_id' => $userId,
                'gerente_id' => $gerenteId,
                'gerente_percentage' => $gerentePercentage,
                'criado_por' => Auth::id()
            ]);

        } catch (\Exception $e) {
            Log::error('[AUTO SPLIT GERENTE] Erro ao criar split automático', [
                'user_id' => $userId,
                'gerente_id' => $gerenteId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Processa configurações de affiliate quando usuário é editado
     * 
     * @param User $user
     * @param Request $request
     */
    /**
     * Salvar configurações de afiliados
     */
    public function salvarAfiliados($id, Request $request)
    {
        try {
            $user = User::findOrFail($id);
            
            $isAffiliate = $request->has('is_affiliate') && $request->input('is_affiliate') == '1';
            $affiliatePercentage = $request->input('affiliate_percentage', 0);
            
            if ($isAffiliate) {
                // Validar porcentagem
                if ($affiliatePercentage < 0 || $affiliatePercentage > 10) {
                    return response()->json([
                        'success' => false,
                        'message' => 'A porcentagem de affiliate deve estar entre 0 e 10.'
                    ]);
                }
                
                // Gerar código de affiliate se não existe
                if (!$user->affiliate_code) {
                    $user->affiliate_code = $this->gerarCodigoAffiliateUnico($user);
                    $user->affiliate_link = config('app.url') . '/register?ref=' . $user->affiliate_code;
                }
                
                // Atualizar campos de affiliate
                $user->update([
                    'is_affiliate' => true,
                    'affiliate_percentage' => $affiliatePercentage,
                    'affiliate_code' => $user->affiliate_code,
                    'affiliate_link' => $user->affiliate_link
                ]);

                Log::info('[ADMIN AFILIADOS] Affiliate configurado pelo admin', [
                    'user_id' => $user->id,
                    'affiliate_percentage' => $affiliatePercentage,
                    'affiliate_code' => $user->affiliate_code,
                    'admin_id' => Auth::id()
                ]);
            } else {
                // Desativar affiliate
                $user->update([
                    'is_affiliate' => false,
                    'affiliate_percentage' => 0
                ]);

                Log::info('[ADMIN AFILIADOS] Affiliate desativado pelo admin', [
                    'user_id' => $user->id,
                    'admin_id' => Auth::id()
                ]);
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Configurações de afiliados salvas com sucesso!'
            ]);
            
        } catch (\Exception $e) {
            Log::error('[ADMIN AFILIADOS] Erro ao salvar configurações de afiliados', [
                'user_id' => $id,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Erro ao salvar configurações de afiliados.'
            ]);
        }
    }

    private function processarAffiliateSettings(User $user, Request $request): void
    {
        try {
            $affiliatePercentage = (float)$request->input('affiliate_percentage');
            
            // Gerar código de affiliate se não existe
            if (!$user->affiliate_code) {
                $user->affiliate_code = $this->gerarCodigoAffiliateUnico($user);
                $user->affiliate_link = config('app.url') . '/register?ref=' . $user->affiliate_code;
            }
            
            // Atualizar campos de affiliate
            $user->update([
                'is_affiliate' => true,
                'affiliate_percentage' => $affiliatePercentage,
                'affiliate_code' => $user->affiliate_code,
                'affiliate_link' => $user->affiliate_link
            ]);

            Log::info('[ADMIN AFFILIATE] Affiliate configurado pelo admin', [
                'user_id' => $user->id,
                'affiliate_percentage' => $affiliatePercentage,
                'affiliate_code' => $user->affiliate_code,
                'admin_id' => Auth::id()
            ]);

        } catch (\Exception $e) {
            Log::error('[ADMIN AFFILIATE] Erro ao configurar affiliate', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Gera código único para affiliate
     * 
     * @param User $user
     * @return string
     */
    private function gerarCodigoAffiliateUnico(User $user): string
    {
        $codigoBase = strtoupper(substr($user->user_id, 0, 4));
        $numeroAleatorio = rand(1000, 9999);
        
        $codigoCompleto = $codigoBase . $numeroAleatorio;
        
        // Verificar se código já existe
        while (\App\Models\User::where('affiliate_code', $codigoCompleto)->exists()) {
            $numeroAleatorio = rand(1000, 9999);
            $codigoCompleto = $codigoBase . $numeroAleatorio;
        }
        
        return $codigoCompleto;
    }
}
