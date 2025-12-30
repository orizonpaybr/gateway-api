<?php

namespace Database\Factories;

use App\Models\UsersKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\UsersKey>
 */
class UsersKeyFactory extends Factory
{
    protected $model = UsersKey::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => fake()->unique()->userName(),
            'token' => 'test_token_' . uniqid(),
            'secret' => 'test_secret_' . uniqid(),
            'status' => 1,
        ];
    }
}
















