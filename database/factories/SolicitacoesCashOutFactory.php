<?php

namespace Database\Factories;

use App\Models\SolicitacoesCashOut;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SolicitacoesCashOut>
 */
class SolicitacoesCashOutFactory extends Factory
{
    protected $model = SolicitacoesCashOut::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $uniqueId = uniqid();
        $amount = $this->faker->randomFloat(2, 10, 500);
        $taxaCashOut = $this->faker->randomFloat(2, 1, 10);
        
        return [
            'user_id' => function (array $attributes) {
                // Se user_id foi passado como objeto User, extrair username
                if (isset($attributes['user_id']) && is_object($attributes['user_id'])) {
                    return $attributes['user_id']->username ?? $attributes['user_id']->user_id ?? 'testuser';
                }
                // Se foi passado como string, usar diretamente
                return $attributes['user_id'] ?? User::factory();
            },
            'idTransaction' => 'TXN_OUT_' . $uniqueId,
            'externalreference' => 'EXT_OUT_' . $uniqueId,
            'amount' => $amount,
            'taxa_cash_out' => $taxaCashOut,
            'cash_out_liquido' => $amount - $taxaCashOut,
            'status' => 'PENDING',
            'date' => now(),
            'beneficiaryname' => $this->faker->name(),
            'beneficiarydocument' => $this->faker->numerify('###########'),
            'pix' => 'MANUAL',
            'pixkey' => $this->faker->email(),
            'type' => 'pix',
            'executor_ordem' => 'PagarMe',
            'descricao_transacao' => 'Saque de teste',
        ];
    }

    /**
     * Indicate that the withdrawal is completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'COMPLETED',
        ]);
    }

    /**
     * Indicate that the withdrawal is pending.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'PENDING',
        ]);
    }
}
