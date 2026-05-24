<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AuditLog>
 */
class AuditLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'actor_id' => User::factory()->admin(),
            'action' => fake()->randomElement([
                'user.suspended',
                'user.reactivated',
                'deposit.approved',
                'deposit.rejected',
                'withdrawal.approved',
                'withdrawal.rejected',
                'trade.resolved',
            ]),
            'target_type' => null,
            'target_id' => null,
            'meta' => ['ip' => fake()->ipv4()],
        ];
    }
}
