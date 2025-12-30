<?php

namespace App\Http\Controllers\Admin\Usuarios;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\App;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TaxasController extends Controller
{
    /**
     * Carregar taxas do usuário (personalizadas ou globais)
     */
    public function carregarTaxas($userId)
    {
        try {
            $user = User::findOrFail($userId);
            $setting = App::first();
            
            // Se o usuário tem taxas personalizadas ativas, usar elas
            if ($user->taxas_personalizadas_ativas) {
                $taxas = [
                    'usando_personalizadas' => true,
                    'configuracoes_deposito' => [
                        'taxa_percentual_deposito' => $user->taxa_percentual_deposito ?? $setting->taxa_cash_in_padrao ?? 2.00,
                        'taxa_fixa_deposito' => $user->taxa_fixa_deposito ?? 0.50,
                        'valor_minimo_deposito' => $user->valor_minimo_deposito ?? $setting->deposito_minimo ?? 1.00,
                    ],
                    'configuracoes_saque' => [
                        'taxa_percentual_pix' => $user->taxa_percentual_pix ?? $setting->taxa_cash_out_padrao ?? 2.00,
                        'taxa_minima_pix' => $user->taxa_minima_pix ?? 0.80,
                        'taxa_fixa_pix' => $user->taxa_fixa_pix ?? 0.20,
                        'valor_minimo_saque' => $user->valor_minimo_saque ?? $setting->saque_minimo ?? 1.00,
                        'limite_mensal_pf' => $user->limite_mensal_pf ?? $setting->limite_saque_mensal ?? 50000.00,
                        'taxa_saque_api' => $user->taxa_saque_api ?? $setting->taxa_saque_api_padrao ?? 2.00,
                        'taxa_saque_crypto' => $user->taxa_saque_crypto ?? $setting->taxa_saque_cripto_padrao ?? 2.00,
                    ],
                    'sistema_flexivel' => [
                        'sistema_flexivel_ativo' => $user->sistema_flexivel_ativo ?? false,
                        'valor_minimo_flexivel' => $user->valor_minimo_flexivel ?? $setting->taxa_flexivel_valor_minimo ?? 15.00,
                        'taxa_fixa_baixos' => $user->taxa_fixa_baixos ?? $setting->taxa_flexivel_fixa_baixo ?? 0.80,
                        'taxa_percentual_altos' => $user->taxa_percentual_altos ?? $setting->taxa_flexivel_percentual_alto ?? 2.00,
                    ],
                    'observacoes' => $user->observacoes_taxas ?? '',
                ];
            } else {
                // Usar taxas globais
                $taxas = [
                    'usando_personalizadas' => false,
                    'configuracoes_deposito' => [
                        'taxa_percentual_deposito' => $setting->taxa_cash_in_padrao ?? 2.00,
                        'taxa_fixa_deposito' => 0.50,
                        'valor_minimo_deposito' => $setting->deposito_minimo ?? 1.00,
                    ],
                    'configuracoes_saque' => [
                        'taxa_percentual_pix' => $setting->taxa_cash_out_padrao ?? 2.00,
                        'taxa_minima_pix' => 0.80,
                        'taxa_fixa_pix' => 0.20,
                        'valor_minimo_saque' => $setting->saque_minimo ?? 1.00,
                        'limite_mensal_pf' => $setting->limite_saque_mensal ?? 50000.00,
                        'taxa_saque_api' => $setting->taxa_saque_api_padrao ?? 2.00,
                        'taxa_saque_crypto' => $setting->taxa_saque_cripto_padrao ?? 2.00,
                    ],
                    'sistema_flexivel' => [
                        'sistema_flexivel_ativo' => $setting->taxa_flexivel_ativa ?? false,
                        'valor_minimo_flexivel' => $setting->taxa_flexivel_valor_minimo ?? 15.00,
                        'taxa_fixa_baixos' => $setting->taxa_flexivel_fixa_baixo ?? 0.80,
                        'taxa_percentual_altos' => $setting->taxa_flexivel_percentual_alto ?? 2.00,
                    ],
                    'observacoes' => '',
                ];
            }
            
            return response()->json([
                'success' => true,
                'taxas' => $taxas,
                'usuario' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'cpf_cnpj' => $user->cpf_cnpj,
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Erro ao carregar taxas do usuário: ' . $e->getMessage(), [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Erro ao carregar taxas do usuário'
            ], 500);
        }
    }
    
    /**
     * Salvar taxas personalizadas do usuário
     */
    public function salvarTaxas(Request $request, $userId)
    {
        try {
            $user = User::findOrFail($userId);
            
            // Validar dados
            $request->validate([
                'taxa_percentual_deposito' => 'nullable|numeric|min:0|max:100',
                'taxa_fixa_deposito' => 'nullable|numeric|min:0',
                'valor_minimo_deposito' => 'nullable|numeric|min:0',
                'taxa_percentual_pix' => 'nullable|numeric|min:0|max:100',
                'taxa_minima_pix' => 'nullable|numeric|min:0',
                'taxa_fixa_pix' => 'nullable|numeric|min:0',
                'valor_minimo_saque' => 'nullable|numeric|min:0',
                'limite_mensal_pf' => 'nullable|numeric|min:0',
                'taxa_saque_api' => 'nullable|numeric|min:0|max:100',
                'taxa_saque_crypto' => 'nullable|numeric|min:0|max:100',
                'sistema_flexivel_ativo' => 'boolean',
                'valor_minimo_flexivel' => 'nullable|numeric|min:0',
                'taxa_fixa_baixos' => 'nullable|numeric|min:0',
                'taxa_percentual_altos' => 'nullable|numeric|min:0|max:100',
                'observacoes' => 'nullable|string|max:1000',
            ]);
            
            DB::beginTransaction();
            
            // Ativar taxas personalizadas
            $user->taxas_personalizadas_ativas = true;
            
            // Configurações de Depósito
            $user->taxa_percentual_deposito = $request->taxa_percentual_deposito;
            $user->taxa_fixa_deposito = $request->taxa_fixa_deposito ?? 0.00;
            $user->valor_minimo_deposito = $request->valor_minimo_deposito;
            
            // Configurações de Saque
            $user->taxa_percentual_pix = $request->taxa_percentual_pix;
            $user->taxa_minima_pix = $request->taxa_minima_pix;
            $user->taxa_fixa_pix = $request->taxa_fixa_pix ?? 0.00;
            $user->valor_minimo_saque = $request->valor_minimo_saque;
            $user->limite_mensal_pf = $request->limite_mensal_pf;
            $user->taxa_saque_api = $request->taxa_saque_api;
            $user->taxa_saque_crypto = $request->taxa_saque_crypto;
            
            // Sistema Flexível
            $user->sistema_flexivel_ativo = $request->boolean('sistema_flexivel_ativo');
            $user->valor_minimo_flexivel = $request->valor_minimo_flexivel;
            $user->taxa_fixa_baixos = $request->taxa_fixa_baixos;
            $user->taxa_percentual_altos = $request->taxa_percentual_altos;
            
            // Observações
            $user->observacoes_taxas = $request->observacoes;
            
            $user->save();
            
            // Invalidar cache do perfil do usuário para refletir novas taxas
            \Illuminate\Support\Facades\Cache::forget('user_profile_' . $user->username);
            
            DB::commit();
            
            Log::info('Taxas personalizadas salvas com sucesso', [
                'user_id' => $userId,
                'user_name' => $user->name,
                'taxas_personalizadas_ativas' => true
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Taxas personalizadas salvas com sucesso!',
                'usando_personalizadas' => true
            ]);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $e->errors()
            ], 422);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erro ao salvar taxas personalizadas: ' . $e->getMessage(), [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Erro ao salvar taxas personalizadas'
            ], 500);
        }
    }
    
    /**
     * Desativar taxas personalizadas (voltar para globais)
     */
    public function desativarTaxasPersonalizadas($userId)
    {
        try {
            $user = User::findOrFail($userId);
            
            $user->taxas_personalizadas_ativas = false;
            $user->save();
            
            // Invalidar cache do perfil do usuário para refletir mudança para taxas globais
            \Illuminate\Support\Facades\Cache::forget('user_profile_' . $user->username);
            
            Log::info('Taxas personalizadas desativadas', [
                'user_id' => $userId,
                'user_name' => $user->name
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Taxas personalizadas desativadas. Usuário voltará a usar taxas globais.',
                'usando_personalizadas' => false
            ]);
            
        } catch (\Exception $e) {
            Log::error('Erro ao desativar taxas personalizadas: ' . $e->getMessage(), [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Erro ao desativar taxas personalizadas'
            ], 500);
        }
    }
}