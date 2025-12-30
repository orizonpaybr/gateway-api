<?php

namespace App\Services;

use App\Models\NotificationPreference;
use Illuminate\Support\Facades\{Cache, Log};

class NotificationPreferenceService
{
    private const CACHE_TTL = 3600; // 1 hora
    private const CACHE_PREFIX = 'notif_pref:';
    
    /**
     * Valores padrão para novas preferências
     */
    private const DEFAULT_PREFERENCES = [
        'notify_transactions' => true,
        'notify_deposits' => true,
        'notify_withdrawals' => true,
        'notify_security' => true,
        'notify_system' => true,
    ];

    /**
     * Obter preferências do usuário (com Redis cache)
     * 
     * @param string $userId
     * @return array
     */
    public function getUserPreferences(string $userId): array
    {
        $cacheKey = self::CACHE_PREFIX . $userId;

        try {
            // Usar Cache facade (padronizado - usa Redis se configurado)
            return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($userId) {
                $preferences = NotificationPreference::firstOrCreate(
                    ['user_id' => $userId],
                    self::DEFAULT_PREFERENCES
                );
                
                return $preferences->toArray();
            });
        } catch (\Exception $e) {
            Log::error('Erro ao obter preferências de notificação', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);

            // Fallback: buscar direto do banco
            return NotificationPreference::firstOrCreate(
                ['user_id' => $userId],
                self::DEFAULT_PREFERENCES
            )->toArray();
        }
    }

    /**
     * Atualizar preferências do usuário
     * 
     * @param string $userId
     * @param array $data
     * @return NotificationPreference
     */
    public function updatePreferences(string $userId, array $data): NotificationPreference
    {
        $preferences = NotificationPreference::firstOrCreate(
            ['user_id' => $userId]
        );

        $preferences->update($data);

        // Limpar cache
        $this->clearCache($userId);

        Log::info('Preferências de notificação atualizadas', [
            'user_id' => $userId,
            'preferences' => $data
        ]);

        return $preferences;
    }

    /**
     * Verificar se usuário deve receber notificação
     * 
     * @param string $userId
     * @param string $type
     * @return bool
     */
    public function shouldNotify(string $userId, string $type): bool
    {
        $preferences = $this->getUserPreferences($userId);

        // Verificar preferência por tipo
        return match($type) {
            'transaction', 'transactions' => $preferences['notify_transactions'] ?? true,
            'deposit', 'deposits' => $preferences['notify_deposits'] ?? true,
            'withdraw', 'withdrawal', 'withdrawals' => $preferences['notify_withdrawals'] ?? true,
            'security' => $preferences['notify_security'] ?? true,
            'system' => $preferences['notify_system'] ?? true,
            default => true,
        };
    }

    /**
     * Limpar cache do usuário
     * 
     * @param string $userId
     * @return void
     */
    public function clearCache(string $userId): void
    {
        try {
            $cacheKey = self::CACHE_PREFIX . $userId;
            Cache::forget($cacheKey);
            
            Log::debug('Cache de preferências limpo', ['user_id' => $userId]);
        } catch (\Exception $e) {
            Log::warning('Erro ao limpar cache de preferências', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Desabilitar todas as notificações para um usuário
     * 
     * @param string $userId
     * @return NotificationPreference
     */
    public function disableAllNotifications(string $userId): NotificationPreference
    {
        return $this->updatePreferences($userId, [
            'notify_transactions' => false,
            'notify_deposits' => false,
            'notify_withdrawals' => false,
            'notify_security' => false,
            'notify_system' => false,
        ]);
    }

    /**
     * Habilitar todas as notificações para um usuário
     * 
     * @param string $userId
     * @return NotificationPreference
     */
    public function enableAllNotifications(string $userId): NotificationPreference
    {
        return $this->updatePreferences($userId, self::DEFAULT_PREFERENCES);
    }

    /**
     * Obter estatísticas de preferências
     * 
     * @return array
     */
    public function getStatistics(): array
    {
        try {
            return [
                'total_users' => NotificationPreference::count(),
                'notify_transactions' => NotificationPreference::where('notify_transactions', true)->count(),
                'notify_deposits' => NotificationPreference::where('notify_deposits', true)->count(),
                'notify_withdrawals' => NotificationPreference::where('notify_withdrawals', true)->count(),
                'notify_security' => NotificationPreference::where('notify_security', true)->count(),
                'notify_system' => NotificationPreference::where('notify_system', true)->count(),
            ];
        } catch (\Exception $e) {
            Log::error('Erro ao obter estatísticas de preferências', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
}

