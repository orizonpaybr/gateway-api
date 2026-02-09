<?php

namespace Database\Factories;

use App\Models\Pagarme;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Pagarme>
 */
class PagarmeFactory extends Factory
{
    protected $model = Pagarme::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'status' => 'TRUE',
            'card_enabled' => true,
            'billet_enabled' => true,
            '3ds_enabled' => false,
            'api_key' => 'test_api_key_' . uniqid(),
        ];
    }
}
