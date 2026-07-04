<?php

namespace App\Services;

use App\Constants\UserPermission;
use App\Constants\UserStatus;
use App\Helpers\AppSettingsHelper;
use App\Http\Requests\Api\RegisterUserRequest;
use App\Models\SplitInterno;
use App\Models\User;
use App\Models\UsersKey;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AuthRegistrationService
{
    public function register(RegisterUserRequest $request): User
    {
        $senhaHash = Hash::make($request->password);
        $clienteId = Str::uuid()->toString();
        $dataCadastroFormatada = Carbon::now('America/Sao_Paulo')->format('Y-m-d H:i:s');

        $fotoRgFrente = $this->storeDocument($request->file('documentoFrente'), 'doc_frente_');
        $fotoRgVerso = $this->storeDocument($request->file('documentoVerso'), 'doc_verso_');
        $selfieRg = $this->storeDocument($request->file('selfieDocumento'), 'selfie_');

        $indicadorRef = $request->input('ref');
        $codeRef = uniqid();
        $gerente = $this->resolveManager($indicadorRef);

        $affiliateCode = $this->generateUniqueAffiliateCode($request->username);
        $affiliateLink = config('app.affiliado_url').'/cadastro?ref='.$affiliateCode;

        $setting = AppSettingsHelper::getSettings();
        $taxaFixaDeposito = $setting ? (float) ($setting->taxa_fixa_padrao ?? 1.00) : 1.00;
        $taxaFixaPix = $setting ? (float) ($setting->taxa_fixa_pix ?? 1.00) : 1.00;

        $user = User::create([
            'username' => $request->username,
            'user_id' => $request->username,
            'name' => $request->name,
            'gender' => $request->gender,
            'email' => $request->email,
            'password' => $senhaHash,
            'telefone' => $request->telefone,
            'cpf_cnpj' => $request->cpf_cnpj,
            'saldo' => 0,
            'data_cadastro' => $dataCadastroFormatada,
            'status' => UserStatus::PENDING,
            'permission' => UserPermission::CLIENT,
            'cliente_id' => $clienteId,
            'code_ref' => $codeRef,
            'indicador_ref' => $indicadorRef,
            'gerente_id' => $gerente?->id,
            'gerente_percentage' => $gerente?->gerente_percentage ?? 0.00,
            'avatar' => '/uploads/avatars/avatar_default.jpg',
            'foto_rg_frente' => $fotoRgFrente,
            'foto_rg_verso' => $fotoRgVerso,
            'selfie_rg' => $selfieRg,
            'affiliate_code' => $affiliateCode,
            'affiliate_link' => $affiliateLink,
            'is_affiliate' => true,
            'taxa_fixa_deposito' => $taxaFixaDeposito,
            'taxa_fixa_pix' => $taxaFixaPix,
        ]);

        Log::info('[REGISTRO] Código de afiliado gerado automaticamente', [
            'user_id' => $request->username,
            'affiliate_code' => $affiliateCode,
            'affiliate_link' => $affiliateLink,
        ]);

        UsersKey::create([
            'user_id' => $user->user_id,
            'token' => Str::uuid()->toString(),
            'secret' => Str::uuid()->toString(),
            'status' => 'active',
        ]);

        $this->linkAffiliateReferral($user, $indicadorRef);
        $this->createManagerSplit($user, $gerente);

        return $user;
    }

    private function storeDocument(?UploadedFile $file, string $prefix): ?string
    {
        if (! $file) {
            return null;
        }

        $filename = $prefix.time().'_'.uniqid().'.'.$file->getClientOriginalExtension();
        $saved = $file->storeAs('uploads/documentos', $filename, 'public');

        if (! $saved) {
            Log::error('[REGISTRO] Falha ao salvar documento', ['prefix' => $prefix]);

            return null;
        }

        $path = '/storage/uploads/documentos/'.$filename;
        Log::info('[REGISTRO] Documento salvo', ['path' => $path]);

        return $path;
    }

    private function resolveManager(?string $indicadorRef): ?User
    {
        $gerente = null;

        try {
            $gerente = User::where('permission', UserPermission::MANAGER)
                ->withCount('clientes')
                ->orderBy('clientes_count', 'asc')
                ->first();
        } catch (\Exception $e) {
            Log::warning('Erro ao buscar gerente com withCount, tentando sem', [
                'error' => $e->getMessage(),
            ]);
            $gerente = User::where('permission', UserPermission::MANAGER)->first();
        }

        if ($indicadorRef) {
            $indicador = User::where('code_ref', $indicadorRef)->first();
            if ($indicador && $indicador->permission === UserPermission::MANAGER) {
                return $indicador;
            }
        }

        return $gerente;
    }

    private function generateUniqueAffiliateCode(string $username): string
    {
        $userIdClean = preg_replace('/[^a-zA-Z0-9]/', '', $username);
        $codigoBase = strtoupper(substr($userIdClean, 0, 4));

        do {
            $affiliateCode = $codigoBase.rand(1000, 9999);
        } while (User::where('affiliate_code', $affiliateCode)->exists());

        return $affiliateCode;
    }

    private function linkAffiliateReferral(User $user, ?string $affiliateCode): void
    {
        if (! $affiliateCode) {
            return;
        }

        $affiliateUser = User::where('affiliate_code', $affiliateCode)
            ->where('id', '!=', $user->id)
            ->first();

        if (! $affiliateUser) {
            return;
        }

        $user->update(['affiliate_id' => $affiliateUser->id]);

        Log::info('[REGISTRO AFFILIATE API] Usuário registrado via affiliate', [
            'novo_usuario_id' => $user->id,
            'affiliate_id' => $affiliateUser->id,
            'affiliate_code' => $affiliateCode,
        ]);
    }

    private function createManagerSplit(User $user, ?User $gerente): void
    {
        if (! $gerente || $gerente->gerente_percentage <= 0) {
            return;
        }

        try {
            SplitInterno::create([
                'usuario_pagador_id' => $user->id,
                'usuario_beneficiario_id' => $gerente->id,
                'porcentagem_split' => $gerente->gerente_percentage,
                'tipo_taxa' => SplitInterno::TAXA_DEPOSITO,
                'ativo' => true,
                'criado_por_admin_id' => 1,
                'data_inicio' => now(),
                'data_fim' => null,
            ]);
        } catch (\Exception $e) {
            Log::error('[REGISTRO AUTOMATICO API] Erro ao criar split interno', [
                'erro' => $e->getMessage(),
                'novo_usuario_id' => $user->id,
                'gerente_id' => $gerente->id,
            ]);
        }
    }
}
