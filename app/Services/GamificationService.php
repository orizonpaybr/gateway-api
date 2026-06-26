<?php

namespace App\Services;

use App\Models\{Nivel, Solicitacoes, User};
use App\Services\CacheKeyService;
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
     * Obtém todos os níveis ordenados (com cache). TTL e chave centralizados em CacheKeyService.
     *
     * @return Collection
     */
    public function getNiveis(): Collection
    {
        $cacheKey = CacheKeyService::gamificationNiveis();
        $ttl = CacheKeyService::TTL_GAMIFICATION_NIVEIS;

        return Cache::remember($cacheKey, $ttl, function () {
            Log::debug('Cache miss: carregando níveis do banco de dados');

            $niveis = Nivel::query()
                ->orderBy('minimo', 'asc')
                ->get();

            if ($niveis->isEmpty()) {
                Log::warning('Tabela niveis vazia; usando níveis padrão da Jornada Coratri');
                return $this->getDefaultNiveis();
            }

            return $niveis;
        });
    }

    /**
     * Níveis padrão quando a tabela está vazia (evita trilha/nível null).
     *
     * @return Collection
     */
    private function getDefaultNiveis(): Collection
    {
        $defaults = [
            (object)['id' => 1, 'nome' => 'Bronze', 'minimo' => 0.0, 'maximo' => 100000.0, 'cor' => '#CD7F32', 'icone' => null],
            (object)['id' => 2, 'nome' => 'Prata', 'minimo' => 100000.01, 'maximo' => 500000.0, 'cor' => '#C0C0C0', 'icone' => null],
            (object)['id' => 3, 'nome' => 'Ouro', 'minimo' => 500000.01, 'maximo' => 1000000.0, 'cor' => '#FFD700', 'icone' => null],
            (object)['id' => 4, 'nome' => 'Safira', 'minimo' => 1000000.01, 'maximo' => 5000000.0, 'cor' => '#0F52BA', 'icone' => null],
            (object)['id' => 5, 'nome' => 'Diamante', 'minimo' => 5000000.01, 'maximo' => 10000000.0, 'cor' => '#B9F2FF', 'icone' => null],
        ];
        return collect($defaults);
    }
    
    /**
     * Invalida o cache de níveis
     * Deve ser chamado após atualizar
     * 
     * @return void
     */
    public function invalidateCacheNiveis(): void
    {
        CacheKeyService::forgetGamificationNiveis();
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
        // Calcula total de depósitos confirmados (exclui mediação/disputa/estorno)
        $depositos = $this->getTotalDepositos($user);
        
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
     * Calcula total de depósitos confirmados do usuário (jornada / gamificação).
     * Não inclui MEDIATION, CHARGEBACK, DISPUTE nem REFUNDED.
     */
    private function getTotalDepositos(User $user): float
    {
        return (float) Solicitacoes::forAccount($user)
            ->confirmedRevenue()
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

