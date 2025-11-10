<?php

namespace App\Helpers;

use App\Models\App;
use Illuminate\Support\Facades\{Cache, Log};

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
            // Usar Cache facade (padronizado - usa Redis se configurado)
            return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
                return App::first();
            });
        } catch (\Exception $e) {
            Log::warning('Erro ao usar cache de App settings, usando query direta', [
                'error' => $e->getMessage()
            ]);
            // Fallback: buscar sem cache
            return App::first();
        }
    }
    
    /**
     * Limpar cache de configurações do App
     */
    public static function forgetCache(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (\Exception $e) {
            Log::warning('Erro ao limpar cache de App settings', [
                'error' => $e->getMessage()
            ]);
        }
    }
}

