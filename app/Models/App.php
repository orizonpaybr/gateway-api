<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class App extends Model
{
    protected $table = "app";

    protected $fillable = [
        'gateway_banner_home',
        'numero_users',
        'faturamento_total',
        'total_transacoes',
        'visitantes',
        'manutencao',
        'baseline',
        'taxa_fixa_pix',
        'taxa_fixa_padrao',
        'taxa_fixa_padrao_cash_out',
        'sms_url_cadastro_pendente',
        'sms_url_cadastro_ativo',
        'sms_url_notificacao_user',
        'sms_url_redefinir_senha',
        'sms_url_autenticar_admin',
        'limite_saque_mensal',
        'limite_saque_automatico',
        'niveis_ativo',
        "gerente_active",
        "gerente_percentage",
        "saque_automatico",
        "global_ips",
        "taxa_por_fora_api",
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
        // Casts de valores numéricos (taxas em centavos)
        'taxa_fixa_padrao' => 'decimal:2',
        'taxa_fixa_padrao_cash_out' => 'decimal:2',
        'taxa_fixa_pix' => 'decimal:2',
        'limite_saque_mensal' => 'decimal:2',
    ];

    /**
     * Verificar se IP está na whitelist global
     */
    public function isIpWhitelisted(string $ip): bool
    {
        $globalIps = $this->global_ips ?? [];
        return in_array($ip, $globalIps);
    }

    /**
     * Adicionar IP à whitelist global
     */
    public function addGlobalIp(string $ip): void
    {
        $globalIps = $this->global_ips ?? [];
        if (!in_array($ip, $globalIps)) {
            $globalIps[] = $ip;
            $this->global_ips = $globalIps;
            $this->save();
        }
    }

    /**
     * Remover IP da whitelist global
     */
    public function removeGlobalIp(string $ip): void
    {
        $globalIps = $this->global_ips ?? [];
        $this->global_ips = array_values(array_filter($globalIps, fn($item) => $item !== $ip));
        $this->save();
    }

    /**
     * Obter taxa de depósito aplicável (taxa fixa em centavos)
     */
    public function getDepositTax(float $amount): float
    {
        return (float) ($this->taxa_fixa_padrao ?? 1);
    }

    /**
     * Obter taxa de saque PIX aplicável (taxa fixa em centavos)
     */
    public function getWithdrawalTax(float $amount): float
    {
        return (float) ($this->taxa_fixa_pix ?? 1);
    }
}
