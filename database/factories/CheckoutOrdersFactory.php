<?php

namespace Database\Factories;

use App\Models\CheckoutOrders;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CheckoutOrders>
 */
class CheckoutOrdersFactory extends Factory
{
    protected $model = CheckoutOrders::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'order_id' => 'ORDER_' . uniqid(),
            'external_reference' => 'EXT_' . uniqid(),
            'amount' => fake()->randomFloat(2, 10, 5000),
            'payment_method' => fake()->randomElement(['credit_card', 'boleto', 'pix']),
            'status' => 'pending',
            'client_name' => fake()->name(),
            'client_email' => fake()->safeEmail(),
            'client_document' => fake()->numerify('###########'),
            'taxa_cash_in' => fake()->randomFloat(2, 0, 50),
            'deposito_liquido' => fake()->randomFloat(2, 10, 5000),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
