<?php

namespace App\Services;

use App\Models\User;
use App\Models\UsersKey;
use Illuminate\Support\Facades\{Auth, Cache, DB, Hash, Log, Redis};
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
        
        // Usar Redis explicitamente (seguindo padrão do projeto)
        try {
            $cached = Redis::get($cacheKey);
            if ($cached) {
                $userData = json_decode($cached, true);
                if ($userData && isset($userData['id'])) {
                    // Reconstruir modelo User a partir dos dados em cache
                    $user = User::find($userData['id']);
                    if ($user) {
                        // Se precisar de relações e não tiver no cache, buscar
                        if ($withRelations && !isset($userData['depositos'])) {
                            $user->load([
                                'depositos' => fn($q) => $q->latest()->limit(10),
                                'saques' => fn($q) => $q->latest()->limit(10),
                                'chaves'
                            ]);
                        }
                        return $user;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning('Erro ao ler cache Redis de usuário, usando query direta', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
        
        // Se não estiver no cache, buscar do banco
        $query = User::where('id', $userId);
        
        if ($withRelations) {
            $query->with([
                'depositos' => fn($q) => $q->latest()->limit(10),
                'saques' => fn($q) => $q->latest()->limit(10),
                'chaves'
            ]);
        }
        
        $user = $query->first();
        
        // Armazenar no Redis
        if ($user) {
            try {
                // Armazenar apenas dados básicos (não relações completas)
                $cacheData = $user->toArray();
                Redis::setex($cacheKey, self::CACHE_TTL_USER, json_encode($cacheData));
            } catch (\Exception $e) {
                Log::warning('Erro ao escrever cache Redis de usuário', [
                    'user_id' => $userId,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return $user;
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
            // Gerar dados padrão
            $userData = [
                'username' => $data['username'],
                'user_id' => $data['username'],
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'telefone' => $data['telefone'] ?? null,
                'cpf_cnpj' => $data['cpf_cnpj'] ?? null,
                'saldo' => $data['saldo'] ?? 0,
                'data_cadastro' => Carbon::now()->format('d/m/Y H:i'),
                'status' => $data['status'] ?? UserStatus::PENDING,
                'permission' => $data['permission'] ?? UserPermission::CLIENT,
                'code_ref' => $this->generateUniqueRefCode(),
                'avatar' => "/uploads/avatars/avatar_default.jpg",
            ];
            
            // Campos opcionais
            $optionalFields = [
                'cpf', 'data_nascimento', 'nome_fantasia', 'razao_social', 
                'cep', 'rua', 'estado', 'cidade', 'bairro', 'numero_residencia', 'complemento',
                'media_faturamento', 'indicador_ref', 'gerente_id'
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
                'name', 'email', 'telefone', 'cpf_cnpj', 'cpf', 'data_nascimento',
                'nome_fantasia', 'razao_social', 'status', 'permission', 'saldo',
                'cep', 'rua', 'estado', 'cidade', 'bairro', 'numero_residencia', 'complemento',
                'media_faturamento', 'taxa_cash_in', 'taxa_cash_out', 
                'taxa_cash_in_fixa', 'taxa_cash_out_fixa', 'gerente_id',
                'taxas_personalizadas_ativas', 'taxa_percentual_deposito', 'taxa_fixa_deposito',
                'valor_minimo_deposito', 'taxa_percentual_pix', 'taxa_minima_pix', 'taxa_fixa_pix',
                'valor_minimo_saque', 'limite_mensal_pf',
                // Saque API/Cripto
                'taxa_saque_api', 'taxa_saque_crypto',
                // Sistema flexível
                'sistema_flexivel_ativo', 'valor_minimo_flexivel', 'taxa_fixa_baixos', 'taxa_percentual_altos',
                'taxa_flexivel_ativa', 'taxa_flexivel_valor_minimo', 'taxa_flexivel_fixa_baixo', 'taxa_flexivel_percentual_alto',
                // Observações
                'observacoes_taxas',
                // Adquirentes / overrides
                'preferred_adquirente', 'adquirente_override',
                'preferred_adquirente_card_billet', 'adquirente_card_billet_override'
            ];
            
            $updateData = [];
            // Campos que devem ser convertidos de string vazia para null
            $nullableFields = ['telefone', 'data_nascimento', 'cpf_cnpj', 'cpf', 'cep', 'rua', 'estado', 'cidade', 'bairro', 'numero_residencia', 'complemento', 'observacoes_taxas'];
            
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
            
            // Atualizar senha se fornecida
            if (!empty($data['password'])) {
                $updateData['password'] = Hash::make($data['password']);
            }
            
            $user->update($updateData);
            
            // Limpar cache do usuário
            CacheKeyService::forgetUser($userId);
            CacheKeyService::forgetUsersStats();
            CacheKeyService::forgetDashboardStats();
            
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

