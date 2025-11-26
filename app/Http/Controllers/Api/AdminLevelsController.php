<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateNivelRequest;
use App\Http\Resources\{NivelResource};
use App\Models\{App, Nivel};
use App\Events\LevelUpdated;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\{Cache, DB, Log, Validator};

/**
 * Controller para gerenciar níveis de gamificação (Admin)
 * 
 * @package App\Http\Controllers\Api
 */
class AdminLevelsController extends Controller
{
    /**
     * Listar todos os níveis com configuração do sistema
     * 
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        try {
            $niveis = Nivel::orderBy('minimo', 'asc')->get();
            $settings = App::first();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'niveis' => NivelResource::collection($niveis),
                    'niveis_ativo' => (bool) $settings->niveis_ativo ?? false,
                ],
            ], 200);
            
        } catch (\Exception $e) {
            Log::error('Erro ao listar níveis', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Erro ao listar níveis.',
            ], 500);
        }
    }
    
    /**
     * Obter um nível específico
     * 
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            $nivel = Nivel::find($id);
            
            if (!$nivel) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nível não encontrado.',
                ], 404);
            }
            
            return response()->json([
                'success' => true,
                'data' => new NivelResource($nivel),
            ], 200);
            
        } catch (\Exception $e) {
            Log::error('Erro ao obter nível', [
                'nivel_id' => $id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Erro ao obter nível.',
            ], 500);
        }
    }
    
    /**
     * Atualizar nível existente
     * 
     * @param UpdateNivelRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(UpdateNivelRequest $request, int $id): JsonResponse
    {
        $nivel = Nivel::find($id);
        
        if (!$nivel) {
            return response()->json([
                'success' => false,
                'message' => 'Nível não encontrado.',
            ], 404);
        }
        
        DB::beginTransaction();
        
        try {
            $nivel->update($request->validated());
            
            // Disparar evento (cache invalidation + auditoria)
            event(new LevelUpdated($nivel->fresh(), $request->user()?->user_id));
            
            DB::commit();
            
            Log::info('Nível atualizado com sucesso', [
                'nivel_id' => $nivel->id,
                'nome' => $nivel->nome,
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Nível atualizado com sucesso!',
                'data' => new NivelResource($nivel->fresh()),
            ], 200);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Erro ao atualizar nível', [
                'nivel_id' => $id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Erro ao atualizar nível.',
            ], 500);
        }
    }
    
    /**
     * Ativar/Desativar sistema de níveis
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function toggleActive(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'niveis_ativo' => 'required|boolean',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erro de validação.',
                'errors' => $validator->errors(),
            ], 422);
        }
        
        try {
            $settings = App::first();
            
            if (!$settings) {
                return response()->json([
                    'success' => false,
                    'message' => 'Configurações do sistema não encontradas.',
                ], 404);
            }
            
            $settings->update(['niveis_ativo' => $request->boolean('niveis_ativo')]);
            
            // Limpar cache de gamificação
            $this->clearGamificationCache();
            
            $status = $request->boolean('niveis_ativo') ? 'ativado' : 'desativado';
            
            Log::info("Sistema de níveis $status", [
                'niveis_ativo' => $request->boolean('niveis_ativo'),
            ]);
            
            return response()->json([
                'success' => true,
                'message' => "Sistema de níveis $status com sucesso!",
                'data' => [
                    'niveis_ativo' => $request->boolean('niveis_ativo'),
                ],
            ], 200);
            
        } catch (\Exception $e) {
            Log::error('Erro ao atualizar status do sistema de níveis', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Erro ao atualizar status do sistema de níveis.',
            ], 500);
        }
    }
    
    /**
     * Endpoint para limpar cache manualmente (útil para testes)
     * 
     * @return JsonResponse
     */
    public function clearCache(): JsonResponse
    {
        try {
            $this->clearGamificationCache();
            
            return response()->json([
                'success' => true,
                'message' => 'Cache de gamificação limpo com sucesso.',
            ], 200);
            
        } catch (\Exception $e) {
            Log::error('Erro ao limpar cache via endpoint', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Erro ao limpar cache.',
            ], 500);
        }
    }
    
    /**
     * Limpar cache de gamificação de todos os usuários
     * 
     * @return void
     */
    private function clearGamificationCache(): void
    {
        try {
            Log::info('Iniciando limpeza do cache de gamificação');

            // Invalida cache de níveis no Service
            app(\App\Services\GamificationService::class)->invalidateCacheNiveis();
            
            // Flush geral para limpar cache de dados de usuários
            // (gamification_data_user_*, sidebar_gamification_user_*, etc)
            Cache::flush();

            Log::info('Cache de gamificação limpo com sucesso');
            
        } catch (\Exception $e) {
            Log::error('Erro ao limpar cache de gamificação', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}


