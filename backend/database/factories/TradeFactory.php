<?php

namespace Database\Factories;

use App\Models\CurrencyPair;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Trade>
 */
class TradeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'currency_pair_id' => CurrencyPair::factory(),
            'direction' => fake()->randomElement(['buy', 'sell']),
            'stake' => fake()->randomFloat(2, 10, 1000),
            'status' => 'open',
            'outcome' => null,
            'pnl' => null,
            'opened_at' => now(),
            'resolved_at' => null,
            'resolved_by' => null,
        ];
    }

    public function closed(): static
    {
        return $this->state(function (array $attributes) {
            $outcome = fake()->randomElement(['win', 'loss']);
            $stake = (float) $attributes['stake'];

            return [
                'status' => 'closed',
                'outcome' => $outcome,
                'pnl' => $outcome === 'win' ? $stake : -$stake,
                'resolved_at' => now(),
                'resolved_by' => User::factory()->admin(),
            ];
        });
    }
}
