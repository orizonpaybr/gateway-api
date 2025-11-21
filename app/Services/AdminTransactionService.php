<?php

namespace App\Services;

use App\Models\{App, Solicitacoes, SolicitacoesCashOut, User};
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Serviço para gerenciar transações administrativas manuais
 * Centraliza lógica de negócio de depósitos e saques
 * 
 * @package App\Services
 */
class AdminTransactionService
{
    private const CACHE_SETTINGS_KEY = 'app:settings';
    private const CACHE_SETTINGS_TTL = 3600; // 1 hora
    
    /**
     * Buscar usuário por user_id
     * 
     * @param string $userId
     * @return User|null
     */
    public function findUser(string $userId): ?User
    {
        return User::where('user_id', $userId)->first();
    }
    
    /**
     * Obter configurações da aplicação (com cache)
     * 
     * @return App|null
     */
    public function getAppSettings(): ?App
    {
        return Cache::remember(
            self::CACHE_SETTINGS_KEY,
            self::CACHE_SETTINGS_TTL,
            fn() => App::first()
        );
    }
    
    /**
     * Gerar ID único para transação
     * 
     * @return string UUID sem hífens
     */
    public function generateTransactionId(): string
    {
        return str_replace('-', '', (string) Str::uuid());
    }
    
    /**
     * Criar registro de depósito
     * 
     * @param User $user
     * @param float $amount
     * @param float $depositoLiquido
     * @param float $taxaCashIn
     * @param string $description
     * @param string $idTransaction
     * @return Solicitacoes
     */
    public function createDepositRecord(
        User $user,
        float $amount,
        float $depositoLiquido,
        float $taxaCashIn,
        string $description,
        string $idTransaction
    ): Solicitacoes {
        $now = Carbon::now();
        
        return Solicitacoes::create([
            'user_id' => $user->user_id,
            'externalreference' => env('APP_NAME') . '_' . $idTransaction,
            'amount' => $amount,
            'client_name' => $user->name,
            'client_document' => $user->cpf_cnpj,
            'client_email' => $user->email,
            'date' => $now->format('Y-m-d H:i:s'),
            'status' => 'PAID_OUT',
            'idTransaction' => $idTransaction,
            'deposito_liquido' => $depositoLiquido,
            'qrcode_pix' => '',
            'paymentcode' => '',
            'paymentCodeBase64' => '',
            'adquirente_ref' => env('APP_NAME'),
            'taxa_cash_in' => $taxaCashIn,
            'taxa_pix_cash_in_adquirente' => 0,
            'taxa_pix_cash_in_valor_fixo' => 0,
            'client_telefone' => $user->telefone,
            'executor_ordem' => env('APP_NAME'),
            'descricao_transacao' => $description,
        ]);
    }
    
    /**
     * Criar registro de saque
     * 
     * @param User $user
     * @param float $amount
     * @param float $saqueLiquido
     * @param float $taxaCashOut
     * @param string $description
     * @param string $idTransaction
     * @return SolicitacoesCashOut
     */
    public function createWithdrawalRecord(
        User $user,
        float $amount,
        float $saqueLiquido,
        float $taxaCashOut,
        string $description,
        string $idTransaction
    ): SolicitacoesCashOut {
        $now = Carbon::now();
        
        return SolicitacoesCashOut::create([
            'user_id' => $user->user_id,
            'externalreference' => env('APP_NAME') . '_' . $idTransaction,
            'amount' => $amount,
            'beneficiaryname' => $user->name,
            'beneficiarydocument' => $user->cpf_cnpj,
            'pix' => 'MANUAL',
            'pixkey' => 'MANUAL',
            'date' => $now->format('Y-m-d H:i:s'),
            'status' => 'PAID_OUT',
            'type' => 'pix',
            'idTransaction' => $idTransaction,
            'taxa_cash_out' => $taxaCashOut,
            'cash_out_liquido' => $saqueLiquido,
            'descricao_transacao' => $description,
            'executor_ordem' => env('APP_NAME'),
        ]);
    }
    
    /**
     * Verificar se usuário tem saldo suficiente
     * 
     * @param User $user
     * @param float $requiredAmount
     * @return bool
     */
    public function hasSufficientBalance(User $user, float $requiredAmount): bool
    {
        return $user->saldo >= $requiredAmount;
    }
    
    /**
     * Invalidar cache de configurações
     * 
     * @return void
     */
    public function invalidateSettingsCache(): void
    {
        Cache::forget(self::CACHE_SETTINGS_KEY);
    }
}

