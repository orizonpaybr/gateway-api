<?php

namespace App\Services;

use App\Models\{Nivel, Solicitacoes};
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Service para lógica de negócio da gamificação
 * 
 * Responsabilidades:
 * - Cálculo de níveis do usuário
 * - Gestão de cache de níveis
 * - Lógica de progressão
 * 
 * @package App\Services
 */
class GamificationService
{
    /**
     * TTL do cache de níveis (1 hora = 3600 segundos)
     * Níveis mudam raramente, cache pode ser mais longo
     */
    private const NIVEIS_CACHE_TTL = 3600;
    
    /**
     * Chave do cache de níveis
     */
    private const NIVEIS_CACHE_KEY = 'gamification:niveis:all';
    
    /**
     * Obt\u00e9m todos os níveis ordenados (com cache)
     * 
     * @return Collection
     */
    public function getNiveis(): Collection
    {
        return Cache::remember(self::NIVEIS_CACHE_KEY, self::NIVEIS_CACHE_TTL, function () {
            Log::debug('Cache miss: carregando níveis do banco de dados');
            
            return Nivel::query()
                ->orderBy('minimo', 'asc')
                ->get();
        });
    }
    
    /**
     * Invalida o cache de níveis
     * Deve ser chamado após atualizar
     * 
     * @return void
     */
    public function invalidateCacheNiveis(): void
    {
        Cache::forget(self::NIVEIS_CACHE_KEY);
        Log::info('Cache de níveis invalidado');
    }
    
    /**
     * Calcula o nível atual do usuário e o próximo nível
     * 
     * Regras:
     * - O primeiro nível (Bronze) sempre tem mínimo = 0,00
     * - O nível é determinado pelo total de depósitos pagos
     * - Se não encontrar nível, retorna o primeiro (Bronze)
     * 
     * @param object $user
     * @return array{total_depositos: float, nivel_atual: Nivel|null, proximo_nivel: Nivel|null}
     */
    public function meuNivel($user): array
    {
        // Calcula total de depósitos pagos do usuário
        $depositos = $this->getTotalDepositos($user->user_id);
        
        // Busca níveis do cache
        $niveis = $this->getNiveis();
        
        if ($niveis->isEmpty()) {
            return [
                'total_depositos' => $depositos,
                'nivel_atual' => null,
                'proximo_nivel' => null,
            ];
        }
        
        // Determina o nível atual baseado no valor depositado
        $resultado = $this->determinarNivelAtual($depositos, $niveis);
        
        return [
            'total_depositos' => $depositos,
            'nivel_atual' => $resultado['nivel_atual'],
            'proximo_nivel' => $resultado['proximo_nivel'],
        ];
    }
    
    /**
     * Calcula total de depósitos pagos do usuário (pode ser movido para UserRepository)
     * Considera tanto PAID_OUT (automático) quanto COMPLETED (manual/final)
     * 
     * @param string $userId
     * @return float
     */
    private function getTotalDepositos(string $userId): float
    {
        return (float) Solicitacoes::whereIn('status', ['PAID_OUT', 'COMPLETED'])
            ->where('user_id', $userId)
            ->sum('amount');
    }
    
    /**
     * Determina o nível atual do usuário baseado no total depositado
     * 
     * @param float $depositos
     * @param Collection $niveis
     * @return array{nivel_atual: Nivel|null, proximo_nivel: Nivel|null}
     */
    private function determinarNivelAtual(float $depositos, Collection $niveis): array
    {
        $nivelAtual = null;
        $proximoNivel = null;
        
        foreach ($niveis as $index => $nivel) {
            // Se está entre mínimo e máximo (inclusive), este é o nível atual
            if ($depositos >= $nivel->minimo && $depositos <= $nivel->maximo) {
                $nivelAtual = $nivel;
                $proximoNivel = $niveis->get($index + 1);
                break;
            }
            
            // Se chegou no último nível e o usuário passou do máximo,
            // fica preso no último nível
            if ($index === $niveis->count() - 1 && $depositos > $nivel->maximo) {
                $nivelAtual = $nivel;
                $proximoNivel = null;
                break;
            }
        }
        
        // Fallback: se não encontrou nível, assume o primeiro (Bronze)
        if (!$nivelAtual) {
            $nivelAtual = $niveis->first();
            $proximoNivel = $niveis->get(1);
        }
        
        return [
            'nivel_atual' => $nivelAtual,
            'proximo_nivel' => $proximoNivel,
        ];
    }
    
    /**
     * Calcula a próxima meta de gamificação
     * 
     * @param Nivel|null $currentLevel
     * @param Nivel|null $nextLevel
     * @param float $totalDeposited
     * @return string
     */
    public function calculateNextGoal($currentLevel, $nextLevel, float $totalDeposited): string
    {
        if (!$currentLevel) {
            return 'Comece depositando!';
        }
        
        $remainingToNextLevel = $currentLevel->maximo - $totalDeposited;
        
        if ($remainingToNextLevel <= 0) {
            if ($nextLevel) {
                $remainingToNextLevelTarget = $nextLevel->maximo - $totalDeposited;
                if ($remainingToNextLevelTarget > 0) {
                    return 'R$ ' . number_format($remainingToNextLevelTarget, 0, ',', '.');
                }
            }
            return 'Concluído!';
        }
        
        return 'R$ ' . number_format($remainingToNextLevel, 0, ',', '.');
    }
}

