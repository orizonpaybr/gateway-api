<?php

namespace Database\Factories;

use App\Models\Solicitacoes;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Solicitacoes>
 */
class SolicitacoesFactory extends Factory
{
    protected $model = Solicitacoes::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $uniqueId = uniqid();
        $amount = $this->faker->randomFloat(2, 10, 1000);
        $taxaCashIn = $this->faker->randomFloat(2, 1, 10);
        
        return [
            'user_id' => function (array $attributes) {
                // Se user_id foi passado como objeto User, extrair username
                if (isset($attributes['user_id']) && is_object($attributes['user_id'])) {
                    return $attributes['user_id']->username ?? $attributes['user_id']->user_id ?? 'testuser';
                }
                // Se foi passado como string, usar diretamente
                return $attributes['user_id'] ?? User::factory();
            },
            'idTransaction' => 'TXN_' . $uniqueId,
            'externalreference' => 'EXT_' . $uniqueId,
            'amount' => $amount,
            'deposito_liquido' => $amount - $taxaCashIn,
            'taxa_cash_in' => $taxaCashIn,
            'status' => 'WAITING_FOR_APPROVAL',
            'date' => now(),
            'client_name' => $this->faker->name(),
            'client_document' => $this->faker->numerify('###########'),
            'client_email' => $this->faker->email(),
            'client_telefone' => $this->faker->numerify('119########'),
            'qrcode_pix' => 'https://example.com/qr/' . $uniqueId,
            'paymentcode' => 'PAY_' . $uniqueId,
            'paymentCodeBase64' => base64_encode('PAY_' . $uniqueId),
            'adquirente_ref' => 'PagarMe',
            'taxa_pix_cash_in_adquirente' => $this->faker->randomFloat(2, 1, 5),
            'taxa_pix_cash_in_valor_fixo' => $this->faker->randomFloat(2, 0.5, 3),
            'executor_ordem' => 'EXEC_' . $uniqueId,
            'descricao_transacao' => 'Depósito de teste',
        ];
    }

    /**
     * Indicate that the transaction is paid out.
     */
    public function paidOut(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'PAID_OUT',
        ]);
    }

    /**
     * Indicate that the transaction is pending.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'PENDING',
        ]);
    }
}
