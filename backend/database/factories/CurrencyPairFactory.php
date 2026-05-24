<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CurrencyPair>
 */
class CurrencyPairFactory extends Factory
{
    public function definition(): array
    {
        $base = fake()->unique()->randomElement(['EUR', 'GBP', 'AUD', 'NZD', 'CHF', 'CAD', 'JPY']);
        $quote = 'USD';

        return [
            'symbol' => $base.$quote,
            'base' => $base,
            'quote' => $quote,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
