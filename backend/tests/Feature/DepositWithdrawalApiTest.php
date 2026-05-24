<?php

namespace Tests\Feature;

use App\Models\DepositRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepositWithdrawalApiTest extends TestCase
{
    use RefreshDatabase;

    private function authedFor(User $user): static
    {
        $token = $user->createToken('test')->plainTextToken;

        return $this->withHeader('Authorization', "Bearer $token");
    }

    private function fundUser(User $user, float $amount): void
    {
        app(LedgerService::class)->credit($user, 'admin_credit', $amount, 'test funding');
    }

    // -----------------------------------------------------------------
    // POST /api/deposits
    // -----------------------------------------------------------------

    public function test_user_can_create_a_pending_deposit_request(): void
    {
        $user = User::factory()->create();

        $response = $this->authedFor($user)->postJson('/api/deposits', [
            'amount' => 250.00,
            'note' => 'wire transfer 12345',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.note', 'wire transfer 12345');

        $this->assertDatabaseHas('deposit_requests', [
            'user_id' => $user->id,
            'amount' => '250.00',
            'status' => 'pending',
            'reviewed_by' => null,
        ]);

        // No ledger movement on request creation.
        $this->assertSame(0, Transaction::count(), 'Creating a deposit request must not write to the ledger');
        $this->assertSame(0.0, app(LedgerService::class)->balanceFor($user));
    }

    public function test_deposit_amount_must_be_positive(): void
    {
        $user = User::factory()->create();

        $this->authedFor($user)->postJson('/api/deposits', ['amount' => 0])
            ->assertStatus(422)->assertJsonValidationErrors(['amount']);

        $this->authedFor($user)->postJson('/api/deposits', ['amount' => -10])
            ->assertStatus(422)->assertJsonValidationErrors(['amount']);
    }

    public function test_deposit_amount_is_required(): void
    {
        $user = User::factory()->create();

        $this->authedFor($user)->postJson('/api/deposits', [])
            ->assertStatus(422)->assertJsonValidationErrors(['amount']);
    }

    public function test_deposit_creation_requires_authentication(): void
    {
        $this->postJson('/api/deposits', ['amount' => 100])->assertStatus(401);
    }

    // -----------------------------------------------------------------
    // GET /api/deposits
    // -----------------------------------------------------------------

    public function test_deposit_index_returns_only_the_authenticated_users_requests_newest_first(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $older = DepositRequest::factory()->create([
            'user_id' => $alice->id,
            'created_at' => now()->subHour(),
        ]);
        $newer = DepositRequest::factory()->create([
            'user_id' => $alice->id,
            'created_at' => now(),
        ]);
        DepositRequest::factory()->count(3)->create(['user_id' => $bob->id]);

        $response = $this->authedFor($alice)->getJson('/api/deposits');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(2, $data);
        $ids = array_column($data, 'id');
        $this->assertSame([$newer->id, $older->id], $ids);
        foreach ($data as $row) {
            $this->assertSame($alice->id, $row['user_id']);
            $this->assertArrayHasKey('status', $row);
        }
    }

    public function test_deposit_index_requires_authentication(): void
    {
        $this->getJson('/api/deposits')->assertStatus(401);
    }

    // -----------------------------------------------------------------
    // POST /api/withdrawals
    // -----------------------------------------------------------------

    public function test_user_can_create_a_pending_withdrawal_request_when_within_balance(): void
    {
        $user = User::factory()->create();
        $this->fundUser($user, 500.00);

        $response = $this->authedFor($user)->postJson('/api/withdrawals', [
            'amount' => 200.00,
            'note' => 'cashout',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.note', 'cashout');

        $this->assertDatabaseHas('withdrawal_requests', [
            'user_id' => $user->id,
            'amount' => '200.00',
            'status' => 'pending',
            'reviewed_by' => null,
        ]);

        // Balance untouched until admin approves.
        $this->assertEqualsWithDelta(500.00, app(LedgerService::class)->balanceFor($user), 0.001);
        $this->assertSame(
            1,
            Transaction::where('user_id', $user->id)->count(),
            'Only the original funding credit should exist — withdrawal request must not move money'
        );
    }

    public function test_withdrawal_rejected_when_amount_exceeds_balance(): void
    {
        $user = User::factory()->create();
        $this->fundUser($user, 100.00);

        $response = $this->authedFor($user)->postJson('/api/withdrawals', [
            'amount' => 150.00,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['amount']);
        $this->assertSame(0, WithdrawalRequest::count(), 'No withdrawal request should be created when over-balance');
    }

    public function test_withdrawal_rejected_with_zero_balance(): void
    {
        $user = User::factory()->create();

        $this->authedFor($user)->postJson('/api/withdrawals', ['amount' => 10.00])
            ->assertStatus(422)->assertJsonValidationErrors(['amount']);

        $this->assertSame(0, WithdrawalRequest::count());
    }

    public function test_withdrawal_amount_must_be_positive(): void
    {
        $user = User::factory()->create();
        $this->fundUser($user, 1000);

        $this->authedFor($user)->postJson('/api/withdrawals', ['amount' => 0])
            ->assertStatus(422)->assertJsonValidationErrors(['amount']);

        $this->authedFor($user)->postJson('/api/withdrawals', ['amount' => -5])
            ->assertStatus(422)->assertJsonValidationErrors(['amount']);
    }

    public function test_withdrawal_amount_is_required(): void
    {
        $user = User::factory()->create();

        $this->authedFor($user)->postJson('/api/withdrawals', [])
            ->assertStatus(422)->assertJsonValidationErrors(['amount']);
    }

    public function test_withdrawal_creation_requires_authentication(): void
    {
        $this->postJson('/api/withdrawals', ['amount' => 50])->assertStatus(401);
    }

    public function test_withdrawal_uses_available_balance_so_open_trade_stakes_already_count(): void
    {
        // Open stakes are already debited via LedgerService at trade-open time,
        // so they are reflected in availableBalance().
        $user = User::factory()->create();
        $this->fundUser($user, 500.00);
        app(LedgerService::class)->debit($user, 'trade_stake', 400.00, 'open trade stake');

        // Balance is 100; trying to withdraw 200 must fail.
        $this->authedFor($user)->postJson('/api/withdrawals', ['amount' => 200])
            ->assertStatus(422)->assertJsonValidationErrors(['amount']);

        // Withdrawing 100 succeeds.
        $this->authedFor($user)->postJson('/api/withdrawals', ['amount' => 100])
            ->assertCreated();
    }

    // -----------------------------------------------------------------
    // GET /api/withdrawals
    // -----------------------------------------------------------------

    public function test_withdrawal_index_returns_only_the_authenticated_users_requests_newest_first(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $older = WithdrawalRequest::factory()->create([
            'user_id' => $alice->id,
            'created_at' => now()->subHours(2),
        ]);
        $newer = WithdrawalRequest::factory()->approved()->create([
            'user_id' => $alice->id,
            'created_at' => now(),
        ]);
        WithdrawalRequest::factory()->count(2)->create(['user_id' => $bob->id]);

        $response = $this->authedFor($alice)->getJson('/api/withdrawals');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertSame([$newer->id, $older->id], array_column($data, 'id'));
        foreach ($data as $row) {
            $this->assertSame($alice->id, $row['user_id']);
            $this->assertArrayHasKey('status', $row);
        }
    }

    public function test_withdrawal_index_requires_authentication(): void
    {
        $this->getJson('/api/withdrawals')->assertStatus(401);
    }
}
