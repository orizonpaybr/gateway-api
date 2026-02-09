<?php

namespace Database\Factories;

use App\Models\App;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\App>
 */
class AppFactory extends Factory
{
    protected $model = App::class;

    /**
     * Define the model's default state.
     * Apenas colunas que existem na tabela app (gateway_name, deposito_maximo,
     * saque_maximo, taxa_cash_in, taxa_cash_out, taxa_pix_cash_in, taxa_pix_cash_out foram
     * removidas ou nunca existiram conforme migrations).
     */
    public function definition(): array
    {
        return [
            'numero_users' => 0,
            'faturamento_total' => 0,
            'total_transacoes' => 0,
            'visitantes' => 0,
            'manutencao' => false,
            'taxa_cash_in_padrao' => 4.00,
            'taxa_cash_out_padrao' => 4.00,
            'taxa_fixa_padrao' => 5.00,
            'taxa_fixa_padrao_cash_out' => 0.00,
            'taxa_fixa_pix' => 0.00,
            'deposito_minimo' => 1.00,
            'saque_minimo' => 10.00,
            'limite_saque_mensal' => 10000000.00,
            'limite_saque_automatico' => 1000.00,
            'saque_automatico' => true,
            'niveis_ativo' => false,
            'global_ips' => [],
            'taxa_por_fora_api' => true,
        ];
    }
}
