<?php

namespace App\Services;

use App\Models\App;
use Illuminate\Support\Facades\Cache;

/**
 * Service Layer para gerenciar configurações do gateway
 * Centraliza lógica de negócio e cache
 * Sistema simplificado: apenas taxas fixas em centavos
 */
class GatewaySettingsService
{
    private const CACHE_KEY = 'app_settings';
    private const CACHE_TTL = 3600;

    /**
     * Obter configurações com cache
     * Cria registro padrão se não existir
     */
    public function getSettings(): App
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            // Buscar configuração existente
            $settings = App::first();
            
            // Se não existir, criar registro padrão
            if (!$settings) {
                $settings = App::create([
                    'taxa_fixa_padrao' => 1.00,
                    'taxa_fixa_pix' => 1.00,
                    'taxa_fixa_padrao_cash_out' => 0.00,
                    'saque_minimo' => 5.00,
                    'limite_saque_mensal' => 50000.00,
                ]);
            }
            
            return $settings;
        });
    }

    /**
     * Atualizar configurações e invalidar cache
     */
    public function updateSettings(array $data): App
    {
        // Buscar primeiro registro ou criar novo
        $settings = App::first();
        if (!$settings) {
            $settings = new App();
        }
        
        // Mapear campos usando método helper
        $this->mapFieldsToModel($settings, $data);
        
        $settings->save();
        
        // Invalidar cache
        $this->clearCache();
        
        return $settings;
    }

    /**
     * Mapear campos da requisição para o modelo
     */
    private function mapFieldsToModel(App $settings, array $data): void
    {
        $fieldMapping = $this->getFieldMapping();
        
        // Atualizar campos mapeados
        foreach ($fieldMapping as $requestField => $dbField) {
            if (array_key_exists($requestField, $data)) {
                $settings->$dbField = $data[$requestField];
            }
        }
        
        // Atualizar campos diretos (sem mapeamento)
        $directFields = $this->getDirectFields();
        foreach ($directFields as $field) {
            if (array_key_exists($field, $data)) {
                $settings->$field = $data[$field];
            }
        }
    }

    /**
     * Formatar dados para resposta da API
     */
    public function formatSettingsResponse(App $settings): array
    {
        return [
            // Taxas de Depósito (apenas fixa em centavos)
            'taxa_fixa_deposito' => (float) ($settings->taxa_fixa_padrao ?? 1.00),
            
            // Taxas de Saque PIX (apenas fixa em centavos)
            'taxa_fixa_pix' => (float) ($settings->taxa_fixa_pix ?? 1.00),
            'limite_mensal_pf' => (float) ($settings->limite_saque_mensal ?? 50000.00),
            
            // Personalização de Relatórios - Entradas
            'relatorio_entradas_mostrar_meio' => (bool) ($settings->relatorio_entradas_mostrar_meio ?? true),
            'relatorio_entradas_mostrar_transacao_id' => (bool) ($settings->relatorio_entradas_mostrar_transacao_id ?? true),
            'relatorio_entradas_mostrar_valor' => (bool) ($settings->relatorio_entradas_mostrar_valor ?? true),
            'relatorio_entradas_mostrar_valor_liquido' => (bool) ($settings->relatorio_entradas_mostrar_valor_liquido ?? true),
            'relatorio_entradas_mostrar_nome' => (bool) ($settings->relatorio_entradas_mostrar_nome ?? true),
            'relatorio_entradas_mostrar_documento' => (bool) ($settings->relatorio_entradas_mostrar_documento ?? true),
            'relatorio_entradas_mostrar_status' => (bool) ($settings->relatorio_entradas_mostrar_status ?? true),
            'relatorio_entradas_mostrar_data' => (bool) ($settings->relatorio_entradas_mostrar_data ?? true),
            'relatorio_entradas_mostrar_taxa' => (bool) ($settings->relatorio_entradas_mostrar_taxa ?? true),
            
            // Personalização de Relatórios - Saídas
            'relatorio_saidas_mostrar_transacao_id' => (bool) ($settings->relatorio_saidas_mostrar_transacao_id ?? true),
            'relatorio_saidas_mostrar_valor' => (bool) ($settings->relatorio_saidas_mostrar_valor ?? true),
            'relatorio_saidas_mostrar_nome' => (bool) ($settings->relatorio_saidas_mostrar_nome ?? true),
            'relatorio_saidas_mostrar_chave_pix' => (bool) ($settings->relatorio_saidas_mostrar_chave_pix ?? true),
            'relatorio_saidas_mostrar_tipo_chave' => (bool) ($settings->relatorio_saidas_mostrar_tipo_chave ?? true),
            'relatorio_saidas_mostrar_status' => (bool) ($settings->relatorio_saidas_mostrar_status ?? true),
            'relatorio_saidas_mostrar_data' => (bool) ($settings->relatorio_saidas_mostrar_data ?? true),
            'relatorio_saidas_mostrar_taxa' => (bool) ($settings->relatorio_saidas_mostrar_taxa ?? true),
            
            // Configurações de Segurança
            'global_ips' => $settings->global_ips ?? [],
        ];
    }

    /**
     * Limpar cache de configurações
     */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Mapeamento de campos da API para o banco
     */
    private function getFieldMapping(): array
    {
        return [
            'taxa_fixa_deposito' => 'taxa_fixa_padrao',
            'taxa_fixa_pix' => 'taxa_fixa_pix',
            'limite_mensal_pf' => 'limite_saque_mensal',
        ];
    }

    /**
     * Campos que não precisam de mapeamento
     */
    private function getDirectFields(): array
    {
        return [
            'relatorio_entradas_mostrar_meio',
            'relatorio_entradas_mostrar_transacao_id',
            'relatorio_entradas_mostrar_valor',
            'relatorio_entradas_mostrar_valor_liquido',
            'relatorio_entradas_mostrar_nome',
            'relatorio_entradas_mostrar_documento',
            'relatorio_entradas_mostrar_status',
            'relatorio_entradas_mostrar_data',
            'relatorio_entradas_mostrar_taxa',
            'relatorio_saidas_mostrar_transacao_id',
            'relatorio_saidas_mostrar_valor',
            'relatorio_saidas_mostrar_nome',
            'relatorio_saidas_mostrar_chave_pix',
            'relatorio_saidas_mostrar_tipo_chave',
            'relatorio_saidas_mostrar_status',
            'relatorio_saidas_mostrar_data',
            'relatorio_saidas_mostrar_taxa',
            'global_ips',
        ];
    }

    /**
     * Regras de validação centralizadas
     */
    public static function getValidationRules(): array
    {
        return [
            // Taxas (fixas em centavos)
            'taxa_fixa_deposito' => 'nullable|numeric|min:0',
            'taxa_fixa_pix' => 'nullable|numeric|min:0',
            'limite_mensal_pf' => 'nullable|numeric|min:0',
            
            // Personalização de Relatórios
            'relatorio_entradas_mostrar_meio' => 'nullable|boolean',
            'relatorio_entradas_mostrar_transacao_id' => 'nullable|boolean',
            'relatorio_entradas_mostrar_valor' => 'nullable|boolean',
            'relatorio_entradas_mostrar_valor_liquido' => 'nullable|boolean',
            'relatorio_entradas_mostrar_nome' => 'nullable|boolean',
            'relatorio_entradas_mostrar_documento' => 'nullable|boolean',
            'relatorio_entradas_mostrar_status' => 'nullable|boolean',
            'relatorio_entradas_mostrar_data' => 'nullable|boolean',
            'relatorio_entradas_mostrar_taxa' => 'nullable|boolean',
            'relatorio_saidas_mostrar_transacao_id' => 'nullable|boolean',
            'relatorio_saidas_mostrar_valor' => 'nullable|boolean',
            'relatorio_saidas_mostrar_nome' => 'nullable|boolean',
            'relatorio_saidas_mostrar_chave_pix' => 'nullable|boolean',
            'relatorio_saidas_mostrar_tipo_chave' => 'nullable|boolean',
            'relatorio_saidas_mostrar_status' => 'nullable|boolean',
            'relatorio_saidas_mostrar_data' => 'nullable|boolean',
            'relatorio_saidas_mostrar_taxa' => 'nullable|boolean',
            
            // Configurações de Segurança
            'global_ips' => 'nullable|array',
            'global_ips.*' => 'nullable|string|ip',
        ];
    }
}
