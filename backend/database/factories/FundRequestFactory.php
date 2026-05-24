<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\FundRequest>
 */
class FundRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => fake()->randomElement(['deposit', 'withdrawal']),
            'amount' => fake()->randomFloat(2, 50, 10000),
            'status' => 'pending',
            'reviewed_by' => null,
            'note' => fake()->optional()->sentence(),
        ];
    }

    public function deposit(): static
    {
        return $this->state(fn () => ['type' => 'deposit']);
    }

    public function withdrawal(): static
    {
        return $this->state(fn () => ['type' => 'withdrawal']);
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => 'approved',
            'reviewed_by' => User::factory()->admin(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'status' => 'rejected',
            'reviewed_by' => User::factory()->admin(),
        ]);
    }
}
