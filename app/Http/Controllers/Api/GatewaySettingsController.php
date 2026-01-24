<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GatewaySettingsService;
use App\Services\TaxValidationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Controller para gerenciar configurações gerais do gateway
 * Delegação de lógica de negócio para GatewaySettingsService
 * 
 * @package App\Http\Controllers\Api
 */
class GatewaySettingsController extends Controller
{
    private GatewaySettingsService $service;

    public function __construct(GatewaySettingsService $service)
    {
        $this->service = $service;
    }

    /**
     * Obter todas as configurações do gateway (com cache)
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSettings()
    {
        try {
            $settings = $this->service->getSettings();
            
            if (!$settings) {
                return response()->json([
                    'success' => false,
                    'message' => 'Configurações do gateway não encontradas.',
                ], 404);
            }
            
            return response()->json([
                'success' => true,
                'data' => $this->service->formatSettingsResponse($settings),
            ], 200);
            
        } catch (\Exception $e) {
            Log::error('Erro ao obter configurações do gateway', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Erro ao obter configurações do gateway.',
            ], 500);
        }
    }
    
    /**
     * Atualizar configurações do gateway
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateSettings(Request $request)
    {
        try {
            // Validar taxas globais
            $validator = TaxValidationService::validateGlobalTaxes($request->all());
            
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos.',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            // Validar consistência das taxas
            $consistencyCheck = TaxValidationService::validateTaxConsistency($request->all());
            if (!$consistencyCheck['valid']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Inconsistência nas configurações de taxas.',
                    'errors' => $consistencyCheck['errors'],
                ], 422);
            }
            
            // Sanitizar dados antes de salvar
            $sanitizedData = TaxValidationService::sanitizeTaxData($request->all());
            
            $settings = $this->service->updateSettings($sanitizedData);
            
            return response()->json([
                'success' => true,
                'message' => 'Configurações atualizadas com sucesso.',
                'data' => $this->service->formatSettingsResponse($settings),
            ], 200);
            
        } catch (\Exception $e) {
            Log::error('Erro ao atualizar configurações do gateway', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Erro ao atualizar configurações do gateway.',
            ], 500);
        }
    }
}
