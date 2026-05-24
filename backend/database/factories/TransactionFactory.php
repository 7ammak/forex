<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Transaction>
 */
class TransactionFactory extends Factory
{
    public function definition(): array
    {
        $type = fake()->randomElement([
            'admin_credit',
            'admin_debit',
            'trade_stake',
            'trade_payout',
            'deposit_approved',
            'withdrawal_approved',
        ]);

        $debit = in_array($type, ['admin_debit', 'trade_stake', 'withdrawal_approved'], true);
        $amount = fake()->randomFloat(2, 10, 5000);

        return [
            'user_id' => User::factory(),
            'type' => $type,
            'amount' => $debit ? -$amount : $amount,
            'reference_type' => null,
            'reference_id' => null,
            'note' => fake()->optional()->sentence(),
        ];
    }
}
