<?php

namespace Database\Factories;

use App\Models\Treeal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Treeal>
 */
class TreealFactory extends Factory
{
    protected $model = Treeal::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'status' => 1, // boolean na migration, MySQL espera 0/1
            'environment' => 'sandbox',
            'qrcodes_api_url' => 'https://api.pix-h.amplea.coop.br',
            'accounts_api_url' => 'https://secureapi.bancodigital.hmg.onz.software/api/v2',
            'taxa_pix_cash_in' => 0.00,
            'taxa_pix_cash_out' => 0.00,
        ];
    }
}
