<?php

namespace App\Services;

use App\Models\NotificationPreference;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class NotificationPreferenceService
{
    private const CACHE_TTL = 3600; // 1 hora
    private const CACHE_PREFIX = 'notif_pref:';
    
    /**
     * Valores padrão para novas preferências
     */
    private const DEFAULT_PREFERENCES = [
        'push_enabled' => true,
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
            // Tentar obter do Redis primeiro
            $cached = Redis::get($cacheKey);
            
            if ($cached) {
                return json_decode($cached, true);
            }

            // Se não estiver no cache, buscar do banco (sem duplicar cache)
            $preferences = NotificationPreference::firstOrCreate(
                ['user_id' => $userId],
                self::DEFAULT_PREFERENCES
            );
            
            $data = $preferences->toArray();

            // Armazenar no Redis
            Redis::setex($cacheKey, self::CACHE_TTL, json_encode($data));

            return $data;

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

        // Se push está desabilitado, não notificar
        if (!($preferences['push_enabled'] ?? true)) {
            return false;
        }

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
            Redis::del($cacheKey);
            
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
            'push_enabled' => false,
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
                'push_enabled' => NotificationPreference::where('push_enabled', true)->count(),
                'push_disabled' => NotificationPreference::where('push_enabled', false)->count(),
                'notify_deposits' => NotificationPreference::where('notify_deposits', true)->count(),
                'notify_withdrawals' => NotificationPreference::where('notify_withdrawals', true)->count(),
            ];
        } catch (\Exception $e) {
            Log::error('Erro ao obter estatísticas de preferências', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
}

