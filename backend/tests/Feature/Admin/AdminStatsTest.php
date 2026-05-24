<?php

namespace Tests\Feature\Admin;

use App\Models\CurrencyPair;
use App\Models\DepositRequest;
use App\Models\Trade;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminStatsTest extends TestCase
{
    use RefreshDatabase;

    private function authedAs(User $user): static
    {
        $token = $user->createToken('test')->plainTextToken;

        return $this->withHeader('Authorization', "Bearer $token");
    }

    public function test_stats_returns_platform_aggregates(): void
    {
        $admin = User::factory()->admin()->create();
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $ledger = app(LedgerService::class);
        $ledger->credit($alice, 'admin_credit', 500, 'seed');
        $ledger->debit($alice, 'admin_debit', 100, 'fee');
        $ledger->credit($bob, 'admin_credit', 250, 'seed');

        $pair = CurrencyPair::factory()->create();
        // resolved_by + reviewed_by states create their own admin users via factory.
        Trade::factory()->count(2)->create(['user_id' => $alice->id, 'currency_pair_id' => $pair->id, 'status' => 'open']);
        Trade::factory()->closed()->create([
            'user_id' => $bob->id,
            'currency_pair_id' => $pair->id,
            'resolved_by' => $admin->id, // reuse the admin so we don't spawn a new one
        ]);

        DepositRequest::factory()->count(3)->create(['user_id' => $alice->id, 'status' => 'pending']);
        DepositRequest::factory()->create([
            'user_id' => $alice->id,
            'status' => 'approved',
            'reviewed_by' => $admin->id,
        ]);
        WithdrawalRequest::factory()->count(1)->create(['user_id' => $bob->id, 'status' => 'pending']);

        $response = $this->authedAs($admin)->getJson('/api/admin/stats');

        $response->assertOk()
            ->assertJsonPath('total_users', 3)
            ->assertJsonPath('open_trades', 2)
            ->assertJsonPath('pending_deposits', 3)
            ->assertJsonPath('pending_withdrawals', 1);
        $this->assertEqualsWithDelta(650.0, (float) $response->json('total_balance'), 0.001);
    }

    public function test_stats_forbids_non_admin_user(): void
    {
        $user = User::factory()->create();
        $this->authedAs($user)->getJson('/api/admin/stats')->assertForbidden();
    }

    public function test_stats_requires_authentication(): void
    {
        $this->getJson('/api/admin/stats')->assertStatus(401);
    }
}
