<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class App extends Model
{
    protected $table = "app";

    protected $fillable = [
        'gateway_name',
        'gateway_logo',
        'gateway_favicon',
        'gateway_banner_home',
        'gateway_color',
        'numero_users',
        'faturamento_total',
        'total_transacoes',
        'visitantes',
        'manutencao',
        'baseline',
        'taxa_fixa_pix',
        'taxa_cash_in_padrao',
        'taxa_cash_out_padrao',
        'taxa_fixa_padrao',
        'taxa_fixa_padrao_cash_out',
        'sms_url_cadastro_pendente',
        'sms_url_cadastro_ativo',
        'sms_url_notificacao_user',
        'sms_url_redefinir_senha',
        'sms_url_autenticar_admin',
        'taxa_pix_valor_real_cash_in_padrao',
        'limite_saque_mensal',
        'limite_saque_automatico',
        'deposito_minimo',
        'saque_minimo',
        'contato',
        'cnpj',
        'niveis_ativo',
        "gerente_active",
        "gerente_percentage",
        "saque_automatico",
        "taxa_flexivel_valor_minimo",
        "taxa_flexivel_fixa_baixo",
        "taxa_flexivel_percentual_alto",
        "taxa_flexivel_ativa",
        "global_ips",
        "taxa_por_fora_api",
        "taxa_saque_api_padrao",
        "taxa_saque_cripto_padrao",
        // Campos de personalização de relatórios de ENTRADAS
        "relatorio_entradas_mostrar_meio",
        "relatorio_entradas_mostrar_transacao_id",
        "relatorio_entradas_mostrar_valor",
        "relatorio_entradas_mostrar_valor_liquido",
        "relatorio_entradas_mostrar_nome",
        "relatorio_entradas_mostrar_documento",
        "relatorio_entradas_mostrar_status",
        "relatorio_entradas_mostrar_data",
        "relatorio_entradas_mostrar_taxa",
        // Campos de personalização de relatórios de SAÍDAS
        "relatorio_saidas_mostrar_transacao_id",
        "relatorio_saidas_mostrar_valor",
        "relatorio_saidas_mostrar_nome",
        "relatorio_saidas_mostrar_chave_pix",
        "relatorio_saidas_mostrar_tipo_chave",
        "relatorio_saidas_mostrar_status",
        "relatorio_saidas_mostrar_data",
        "relatorio_saidas_mostrar_taxa"
    ];

    protected $casts = [
        'niveis_ativo' => 'boolean',
        'gerente_active' => 'boolean',
        'saque_automatico' => 'boolean',
        'taxa_flexivel_ativa' => 'boolean',
        'global_ips' => 'array',
        'taxa_por_fora_api' => 'boolean',
        // Casts para campos de personalização de relatórios
        'relatorio_entradas_mostrar_meio' => 'boolean',
        'relatorio_entradas_mostrar_transacao_id' => 'boolean',
        'relatorio_entradas_mostrar_valor' => 'boolean',
        'relatorio_entradas_mostrar_valor_liquido' => 'boolean',
        'relatorio_entradas_mostrar_nome' => 'boolean',
        'relatorio_entradas_mostrar_documento' => 'boolean',
        'relatorio_entradas_mostrar_status' => 'boolean',
        'relatorio_entradas_mostrar_data' => 'boolean',
        'relatorio_entradas_mostrar_taxa' => 'boolean',
        'relatorio_saidas_mostrar_transacao_id' => 'boolean',
        'relatorio_saidas_mostrar_valor' => 'boolean',
        'relatorio_saidas_mostrar_nome' => 'boolean',
        'relatorio_saidas_mostrar_chave_pix' => 'boolean',
        'relatorio_saidas_mostrar_tipo_chave' => 'boolean',
        'relatorio_saidas_mostrar_status' => 'boolean',
        'relatorio_saidas_mostrar_data' => 'boolean',
        'relatorio_saidas_mostrar_taxa' => 'boolean',
    ];
}
