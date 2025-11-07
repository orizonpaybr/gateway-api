<?php

namespace App\Helpers;

use App\Models\App;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

/**
 * Helper para configurações do App com cache
 * Centraliza acesso a configurações do sistema com cache Redis
 * Segue princípio DRY e otimização de performance
 */
class AppSettingsHelper
{
    private const CACHE_TTL = 3600; // 1 hora
    private const CACHE_KEY = 'app:settings';
    
    /**
     * Obter configurações do App com cache
     * 
     * @return App|null
     */
    public static function getSettings(): ?App
    {
        try {
            $cached = Redis::get(self::CACHE_KEY);
            if ($cached) {
                $data = json_decode($cached, true);
                if ($data) {
                    // Reconstruir modelo App a partir dos dados em cache
                    return App::find($data['id'] ?? null);
                }
            }
        } catch (\Exception $e) {
            Log::warning('Erro ao ler cache Redis de App settings, usando query direta', [
                'error' => $e->getMessage()
            ]);
        }
        
        // Se não estiver no cache, buscar do banco
        $setting = App::first();
        
        // Armazenar no Redis
        if ($setting) {
            try {
                Redis::setex(self::CACHE_KEY, self::CACHE_TTL, json_encode($setting->toArray()));
            } catch (\Exception $e) {
                Log::warning('Erro ao escrever cache Redis de App settings', [
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return $setting;
    }
    
    /**
     * Limpar cache de configurações do App
     */
    public static function forgetCache(): void
    {
        try {
            Redis::del(self::CACHE_KEY);
        } catch (\Exception $e) {
            Log::warning('Erro ao limpar cache Redis de App settings', [
                'error' => $e->getMessage()
            ]);
        }
    }
}

