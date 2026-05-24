<?php

namespace Tests\Feature;

use App\Models\FundRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FundRequestApiTest extends TestCase
{
    use RefreshDatabase;

    private function authedFor(User $user): static
    {
        $token = $user->createToken('test')->plainTextToken;

        return $this->withHeader('Authorization', "Bearer $token");
    }

    // -----------------------------------------------------------------
    // POST /api/fund-requests
    // -----------------------------------------------------------------

    public function test_user_can_submit_a_deposit_request(): void
    {
        $user = User::factory()->create();

        $response = $this->authedFor($user)->postJson('/api/fund-requests', [
            'type' => 'deposit',
            'amount' => 250.00,
            'note' => 'wire transfer 12345',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.type', 'deposit')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.note', 'wire transfer 12345');

        $this->assertDatabaseHas('fund_requests', [
            'user_id' => $user->id,
            'type' => 'deposit',
            'amount' => '250.00',
            'status' => 'pending',
            'reviewed_by' => null,
        ]);

        // No ledger movement on request creation.
        $this->assertSame(0, Transaction::count());
    }

    public function test_user_can_submit_a_withdrawal_request_within_balance(): void
    {
        $user = User::factory()->create();
        app(LedgerService::class)->credit($user, 'admin_credit', 500, 'seed');

        $response = $this->authedFor($user)->postJson('/api/fund-requests', [
            'type' => 'withdrawal',
            'amount' => 200.00,
            'note' => 'cashout',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', 'withdrawal')
            ->assertJsonPath('data.status', 'pending');

        // Balance untouched until admin approves.
        $this->assertEqualsWithDelta(500.00, app(LedgerService::class)->balanceFor($user), 0.001);
        $this->assertSame(1, Transaction::where('user_id', $user->id)->count());
    }

    public function test_withdrawal_request_rejected_when_amount_exceeds_balance(): void
    {
        $user = User::factory()->create();
        app(LedgerService::class)->credit($user, 'admin_credit', 100, 'seed');

        $response = $this->authedFor($user)->postJson('/api/fund-requests', [
            'type' => 'withdrawal',
            'amount' => 150.00,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['amount']);
        $this->assertSame(0, FundRequest::count());
    }

    public function test_withdrawal_request_rejected_with_zero_balance(): void
    {
        $user = User::factory()->create();

        $this->authedFor($user)->postJson('/api/fund-requests', [
            'type' => 'withdrawal',
            'amount' => 10.00,
        ])->assertStatus(422)->assertJsonValidationErrors(['amount']);

        $this->assertSame(0, FundRequest::count());
    }

    public function test_amount_must_be_positive(): void
    {
        $user = User::factory()->create();
        app(LedgerService::class)->credit($user, 'admin_credit', 1000, 'seed');

        $this->authedFor($user)->postJson('/api/fund-requests', [
            'type' => 'deposit',
            'amount' => 0,
        ])->assertStatus(422)->assertJsonValidationErrors(['amount']);

        $this->authedFor($user)->postJson('/api/fund-requests', [
            'type' => 'deposit',
            'amount' => -5,
        ])->assertStatus(422)->assertJsonValidationErrors(['amount']);
    }

    public function test_type_is_validated(): void
    {
        $user = User::factory()->create();

        $this->authedFor($user)->postJson('/api/fund-requests', [
            'type' => 'bogus',
            'amount' => 100,
        ])->assertStatus(422)->assertJsonValidationErrors(['type']);
    }

    public function test_admin_cannot_submit_a_fund_request(): void
    {
        $admin = User::factory()->admin()->create();

        $this->authedFor($admin)->postJson('/api/fund-requests', [
            'type' => 'deposit',
            'amount' => 100,
        ])->assertStatus(422)->assertJsonValidationErrors(['type']);

        $this->assertSame(0, FundRequest::count());
    }

    public function test_create_requires_authentication(): void
    {
        $this->postJson('/api/fund-requests', ['type' => 'deposit', 'amount' => 100])
            ->assertStatus(401);
    }

    // -----------------------------------------------------------------
    // GET /api/fund-requests
    // -----------------------------------------------------------------

    public function test_index_returns_only_the_authenticated_users_requests_newest_first(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $older = FundRequest::factory()->create([
            'user_id' => $alice->id,
            'created_at' => now()->subHour(),
        ]);
        $newer = FundRequest::factory()->create([
            'user_id' => $alice->id,
            'created_at' => now(),
        ]);
        FundRequest::factory()->count(3)->create(['user_id' => $bob->id]);

        $response = $this->authedFor($alice)->getJson('/api/fund-requests');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertSame([$newer->id, $older->id], array_column($data, 'id'));
        foreach ($data as $row) {
            $this->assertSame($alice->id, $row['user_id']);
        }
    }

    public function test_index_filters_by_type(): void
    {
        $user = User::factory()->create();
        FundRequest::factory()->deposit()->count(2)->create(['user_id' => $user->id]);
        FundRequest::factory()->withdrawal()->count(3)->create(['user_id' => $user->id]);

        $deposits = $this->authedFor($user)->getJson('/api/fund-requests?type=deposit');
        $withdrawals = $this->authedFor($user)->getJson('/api/fund-requests?type=withdrawal');

        $this->assertCount(2, $deposits->json('data'));
        $this->assertCount(3, $withdrawals->json('data'));
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/fund-requests')->assertStatus(401);
    }
}
