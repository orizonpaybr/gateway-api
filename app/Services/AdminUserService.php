<?php

namespace App\Services;

use App\Models\User;
use App\Models\UsersKey;
use Illuminate\Support\Facades\{Auth, Cache, DB, Hash, Log};
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Constants\{UserStatus, UserPermission};
use App\Services\CacheKeyService;

/**
 * Service Layer para gerenciamento de usuários administrativos
 * 
 * Implementa:
 * - Cache Redis para performance
 * - Transações de banco de dados
 * - Lógica de negócio centralizada
 * - DRY (Don't Repeat Yourself)
 * - SOLID Principles
 */
class AdminUserService
{
    // TTL do cache em segundos
    private const CACHE_TTL_USER = 300; // 5 minutos
    private const CACHE_TTL_LIST = 120; // 2 minutos
    
    /**
     * Buscar usuário por ID com cache
     *
     * @param int $userId
     * @param bool $withRelations
     * @return User|null
     */
    public function getUserById(int $userId, bool $withRelations = false): ?User
    {
        $cacheKey = CacheKeyService::adminUser($userId, $withRelations);
        
        // Usar Cache facade (padronizado - usa Redis se configurado)
        try {
            $user = Cache::remember($cacheKey, self::CACHE_TTL_USER, function () use ($userId, $withRelations) {
                $query = User::where('id', $userId);
                
                if ($withRelations) {
                    $query->with([
                        'depositos' => fn($q) => $q->latest()->limit(10),
                        'saques' => fn($q) => $q->latest()->limit(10),
                        'chaves'
                    ]);
                }
                
                return $query->first();
            });
            
            // Se precisar de relações e não foram carregadas, buscar agora
            if ($user && $withRelations && !$user->relationLoaded('depositos')) {
                $user->load([
                    'depositos' => fn($q) => $q->latest()->limit(10),
                    'saques' => fn($q) => $q->latest()->limit(10),
                    'chaves'
                ]);
            }
            
            return $user;
        } catch (\Exception $e) {
            Log::warning('Erro ao usar cache de usuário, usando query direta', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            
            // Fallback: buscar sem cache
            $query = User::where('id', $userId);
            
            if ($withRelations) {
                $query->with([
                    'depositos' => fn($q) => $q->latest()->limit(10),
                    'saques' => fn($q) => $q->latest()->limit(10),
                    'chaves'
                ]);
            }
            
            return $query->first();
        }
    }
    
    /**
     * Criar novo usuário
     *
     * @param array $data
     * @return User
     * @throws \Exception
     */
    public function createUser(array $data): User
    {
        DB::beginTransaction();
        
        try {
            // Gerar username automaticamente se não fornecido
            $username = $data['username'] ?? $this->generateUniqueUsername($data['email'] ?? $data['name']);
            
            // Gerar dados padrão
            $userData = [
                'username' => $username,
                'user_id' => $username,
                'cliente_id' => $username,
                'name' => $data['name'],
                'gender' => $data['gender'] ?? null,
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'telefone' => $data['telefone'] ?? null,
                'cpf_cnpj' => $data['cpf_cnpj'] ?? null,
                'saldo' => $data['saldo'] ?? 0,
                'data_cadastro' => Carbon::now('America/Sao_Paulo')->format('Y-m-d H:i:s'),
                'status' => $data['status'] ?? UserStatus::ACTIVE,
                'permission' => $data['permission'] ?? UserPermission::CLIENT,
                'code_ref' => $this->generateUniqueRefCode(),
                'avatar' => "/uploads/avatars/avatar_default.jpg",
            ];
            
            // Campos opcionais (permission e status podem ser sobrescritos se fornecidos)
            $optionalFields = [
                'gender', 'cpf', 'data_nascimento', 'nome_fantasia', 'razao_social', 
                'cep', 'rua', 'estado', 'cidade', 'bairro', 'numero_residencia', 'complemento',
                'media_faturamento', 'indicador_ref', 'gerente_id',
                'permission', 'status'
            ];
            
            foreach ($optionalFields as $field) {
                if (isset($data[$field])) {
                    $userData[$field] = $data[$field];
                }
            }
            
            // Criar usuário
            $user = User::create($userData);
            
            // Criar chaves de API
            $this->createUserApiKeys($user->user_id);
            
            // Limpar cache relacionado
            CacheKeyService::forgetUsersStats();
            CacheKeyService::forgetDashboardStats();
            
            // Se for gerente, limpar cache de gerentes
            if (isset($data['permission']) && $data['permission'] == UserPermission::MANAGER) {
                CacheKeyService::forgetManagers();
            }
            
            DB::commit();
            
            Log::info('Usuário criado pelo admin', [
                'user_id' => $user->id,
                'username' => $user->username,
                'created_by' => Auth::id()
            ]);
            
            return $user;
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Erro ao criar usuário', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Atualizar usuário existente
     *
     * @param int $userId
     * @param array $data
     * @return User
     * @throws \Exception
     */
    public function updateUser(int $userId, array $data): User
    {
        DB::beginTransaction();
        
        try {
            $user = User::findOrFail($userId);
            
            // Campos que podem ser atualizados
            $allowedFields = [
                'name', 'email', 'telefone', 'cpf', 'data_nascimento',
                'nome_fantasia', 'razao_social', 'status', 'permission', 'saldo',
                'cep', 'rua', 'estado', 'cidade', 'bairro', 'numero_residencia', 'complemento',
                'media_faturamento', 'taxa_cash_in', 'taxa_cash_out', 
                'taxa_cash_in_fixa', 'taxa_cash_out_fixa', 'gerente_id',
                // Taxas fixas (em centavos)
                'taxas_personalizadas_ativas', 'taxa_fixa_deposito', 'taxa_fixa_pix',
                'limite_mensal_pf',
                // Observações
                'observacoes_taxas',
                // Adquirentes / overrides
                'preferred_adquirente', 'adquirente_override',
                'preferred_adquirente_card_billet', 'adquirente_card_billet_override'
            ];
            
            $updateData = [];
            // Campos que devem ser convertidos de string vazia para null
            $nullableFields = ['telefone', 'data_nascimento', 'cpf', 'cep', 'rua', 'estado', 'cidade', 'bairro', 'numero_residencia', 'complemento', 'observacoes_taxas'];
            
            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $value = $data[$field];
                    
                    // Converter strings vazias para null em campos nullable
                    if (in_array($field, $nullableFields) && $value === '') {
                        $updateData[$field] = null;
                    } else {
                        $updateData[$field] = $value;
                    }
                }
            }
            
            // IMPORTANTE: Ativar automaticamente taxas personalizadas se uma taxa foi definida
            // Se o usuário definiu taxa_fixa_deposito ou taxa_fixa_pix, ativar automaticamente
            $hasTaxaDeposito = array_key_exists('taxa_fixa_deposito', $data);
            $hasTaxaPix = array_key_exists('taxa_fixa_pix', $data);
            $taxaDepositoValue = $hasTaxaDeposito ? (float) ($data['taxa_fixa_deposito'] ?? 0) : null;
            $taxaPixValue = $hasTaxaPix ? (float) ($data['taxa_fixa_pix'] ?? 0) : null;
            
            // Se uma taxa foi definida e é diferente de zero, ativar taxas personalizadas
            if (($hasTaxaDeposito && $taxaDepositoValue > 0) || ($hasTaxaPix && $taxaPixValue > 0)) {
                // Só atualizar se não foi explicitamente definido como false
                if (!array_key_exists('taxas_personalizadas_ativas', $data) || $data['taxas_personalizadas_ativas'] !== false) {
                    $updateData['taxas_personalizadas_ativas'] = true;
                    Log::info('AdminUserService::updateUser - Ativando taxas personalizadas automaticamente', [
                        'user_id' => $userId,
                        'taxa_fixa_deposito' => $taxaDepositoValue,
                        'taxa_fixa_pix' => $taxaPixValue,
                    ]);
                }
            }
            
            $user->update($updateData);
            
            // Limpar cache do usuário
            CacheKeyService::forgetUser($userId);
            CacheKeyService::forgetUsersStats();
            CacheKeyService::forgetDashboardStats();
            // Limpar cache do perfil (Dados da Conta) para exibir taxas atualizadas
            Cache::forget('user_profile_' . $user->username);
            
            // Se for gerente, limpar cache de gerentes
            if ($user->permission == UserPermission::MANAGER) {
                CacheKeyService::forgetManagers();
            }
            
            DB::commit();
            
            Log::info('Usuário atualizado pelo admin', [
                'user_id' => $userId,
                'updated_fields' => array_keys($updateData),
                'updated_by' => Auth::id()
            ]);
            
            return $user->fresh();
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Erro ao atualizar usuário', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Deletar usuário (soft delete)
     *
     * @param int $userId
     * @return bool
     * @throws \Exception
     */
    public function deleteUser(int $userId): bool
    {
        DB::beginTransaction();
        
        try {
            $user = User::findOrFail($userId);
            
            // Não permitir deletar admin principal
            if ($user->permission == UserPermission::ADMIN && $user->id == 1) {
                throw new \Exception('Não é possível deletar o administrador principal');
            }
            
            // Marcar como inativo (excluído) ao invés de deletar fisicamente
            // Não marcar como banido, pois banido é para bloqueio, não exclusão
            $user->update([
                'status' => UserStatus::INACTIVE,
                'banido' => false
            ]);
            
            // Limpar cache
            CacheKeyService::forgetUser($userId);
            CacheKeyService::forgetUsersStats();
            CacheKeyService::forgetDashboardStats();
            
            // Se era gerente, limpar cache de gerentes
            if ($user->permission == UserPermission::MANAGER) {
                CacheKeyService::forgetManagers();
            }
            
            DB::commit();
            
            Log::warning('Usuário deletado/banido pelo admin', [
                'user_id' => $userId,
                'username' => $user->username,
                'deleted_by' => Auth::id()
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Erro ao deletar usuário', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Aprovar usuário pendente
     *
     * @param int $userId
     * @return User
     * @throws \Exception
     */
    public function approveUser(int $userId): User
    {
        DB::beginTransaction();
        
        try {
            $user = User::findOrFail($userId);
            
            if ($user->status == UserStatus::ACTIVE) {
                throw new \Exception('Usuário já está aprovado');
            }
            
            $user->update([
                'status' => UserStatus::ACTIVE,
                'aprovado_alguma_vez' => true
            ]);
            
            // Limpar cache
            CacheKeyService::forgetUser($userId);
            CacheKeyService::forgetUsersStats();
            CacheKeyService::forgetDashboardStats();
            
            DB::commit();
            
            Log::info('Usuário aprovado pelo admin', [
                'user_id' => $userId,
                'username' => $user->username,
                'approved_by' => Auth::id()
            ]);
            
            // TODO: Enviar notificação/email para o usuário
            
            return $user->fresh();
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Erro ao aprovar usuário', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Bloquear/desbloquear usuário
     *
     * @param int $userId
     * @param bool $block
     * @return User
     * @throws \Exception
     */
    public function toggleUserBlock(int $userId, bool $block = true, bool $approve = false): User
    {
        DB::beginTransaction();
        
        try {
            $user = User::findOrFail($userId);
            
            // Não permitir bloquear admin principal
            if ($user->permission == UserPermission::ADMIN && $user->id == 1) {
                throw new \Exception('Não é possível bloquear o administrador principal');
            }
            
            // Se está desbloqueando e deve aprovar também
            if (!$block && $approve) {
                $user->update([
                    'status' => UserStatus::ACTIVE,
                    'banido' => false,
                    'aprovado_alguma_vez' => true
                ]);
            } else {
                $user->update([
                    'status' => $block ? UserStatus::INACTIVE : UserStatus::ACTIVE,
                    'banido' => $block
                ]);
            }
            
            // Limpar cache
            CacheKeyService::forgetUser($userId);
            CacheKeyService::forgetUsersStats();
            CacheKeyService::forgetDashboardStats();
            
            DB::commit();
            
            $action = $block ? 'bloqueado' : ($approve ? 'desbloqueado e aprovado' : 'desbloqueado');
            Log::warning('Usuário ' . $action . ' pelo admin', [
                'user_id' => $userId,
                'username' => $user->username,
                'action_by' => Auth::id()
            ]);
            
            return $user->fresh();
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Erro ao bloquear/desbloquear usuário', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Bloquear/desbloquear saque do usuário
     *
     * @param int $userId
     * @param bool $block
     * @return User
     * @throws \Exception
     */
    public function toggleWithdrawBlock(int $userId, bool $block = true): User
    {
        DB::beginTransaction();
        
        try {
            $user = User::findOrFail($userId);
            
            // Não permitir bloquear saque do admin principal
            if ($user->permission == UserPermission::ADMIN && $user->id == 1) {
                throw new \Exception('Não é possível bloquear saque do administrador principal');
            }
            
            $user->update([
                'saque_bloqueado' => $block
            ]);
            
            // Limpar cache
            CacheKeyService::forgetUser($userId);
            CacheKeyService::forgetUsersStats();
            CacheKeyService::forgetDashboardStats();
            
            DB::commit();
            
            $action = $block ? 'bloqueado' : 'desbloqueado';
            Log::warning('Saque do usuário ' . $action . ' pelo admin', [
                'user_id' => $userId,
                'username' => $user->username,
                'action_by' => Auth::id()
            ]);
            
            return $user->fresh();
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Erro ao bloquear/desbloquear saque do usuário', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Ajustar saldo do usuário
     *
     * @param int $userId
     * @param float $amount
     * @param string $type ('add' ou 'subtract')
     * @param string $reason
     * @return User
     * @throws \Exception
     */
    public function adjustBalance(int $userId, float $amount, string $type = 'add', string $reason = ''): User
    {
        DB::beginTransaction();
        
        try {
            $user = User::findOrFail($userId);
            
            // Salvar saldo antigo antes de atualizar
            $oldBalance = $user->saldo;
            
            $newBalance = $type === 'add' 
                ? $user->saldo + $amount 
                : $user->saldo - $amount;
            
            if ($newBalance < 0) {
                throw new \Exception('Saldo não pode ser negativo');
            }
            
            $user->update(['saldo' => $newBalance]);
            
            // Limpar cache
            CacheKeyService::forgetUser($userId);
            CacheKeyService::forgetDashboardStats(); // Atualiza saldo total
            
            DB::commit();
            
            Log::info('Saldo ajustado pelo admin', [
                'user_id' => $userId,
                'old_balance' => $oldBalance,
                'new_balance' => $newBalance,
                'amount' => $amount,
                'type' => $type,
                'reason' => $reason,
                'adjusted_by' => Auth::id()
            ]);
            
            return $user->fresh();
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Erro ao ajustar saldo', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Gerar código de referência único
     *
     * @return string
     */
    private function generateUniqueRefCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (User::where('code_ref', $code)->exists());
        
        return $code;
    }

    /**
     * Gerar username único baseado em email ou nome
     *
     * @param string $base
     * @return string
     */
    private function generateUniqueUsername(string $base): string
    {
        // Extrair parte antes do @ se for email
        if (strpos($base, '@') !== false) {
            $base = explode('@', $base)[0];
        }
        
        // Limpar e normalizar
        $base = Str::slug(Str::lower($base), '');
        $base = preg_replace('/[^a-z0-9]/', '', $base);
        
        // Se base estiver vazia ou muito curta, usar prefixo
        if (strlen($base) < 3) {
            $base = 'user' . Str::random(4);
        }
        
        // Garantir unicidade
        $username = $base;
        $counter = 1;
        while (User::where('username', $username)->exists()) {
            $username = $base . $counter;
            $counter++;
        }
        
        return $username;
    }
    
    /**
     * Criar chaves de API para o usuário
     *
     * @param string $userId
     * @return UsersKey
     */
    private function createUserApiKeys(string $userId): UsersKey
    {
        return UsersKey::create([
            'user_id' => $userId,
            'token' => Str::uuid()->toString(),
            'secret' => Str::uuid()->toString(),
            'status' => 1
        ]);
    }
    
}

