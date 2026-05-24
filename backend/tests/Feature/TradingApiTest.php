<?php

namespace Tests\Feature;

use App\Models\CurrencyPair;
use App\Models\Trade;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TradingApiTest extends TestCase
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
    // GET /api/pairs
    // -----------------------------------------------------------------

    public function test_pairs_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/pairs')->assertStatus(401);
    }

    public function test_pairs_endpoint_returns_only_active_pairs_sorted_by_symbol(): void
    {
        CurrencyPair::factory()->create(['symbol' => 'EURUSD', 'base' => 'EUR', 'quote' => 'USD']);
        CurrencyPair::factory()->create(['symbol' => 'AUDUSD', 'base' => 'AUD', 'quote' => 'USD']);
        CurrencyPair::factory()->inactive()->create(['symbol' => 'ZZZINACTIVE', 'base' => 'ZZZ', 'quote' => 'USD']);

        $user = User::factory()->create();

        $response = $this->authedFor($user)->getJson('/api/pairs');

        $response->assertOk();
        $data = $response->json('data');
        $symbols = array_column($data, 'symbol');

        $this->assertSame(['AUDUSD', 'EURUSD'], $symbols);
        $this->assertNotContains('ZZZINACTIVE', $symbols);
    }

    // -----------------------------------------------------------------
    // POST /api/trades
    // -----------------------------------------------------------------

    public function test_user_can_open_a_trade_when_balance_is_sufficient(): void
    {
        $user = User::factory()->create();
        $pair = CurrencyPair::factory()->create(['symbol' => 'EURUSD']);
        $this->fundUser($user, 500.00);

        $response = $this->authedFor($user)->postJson('/api/trades', [
            'currency_pair_id' => $pair->id,
            'direction' => 'buy',
            'stake' => 100.00,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.currency_pair_id', $pair->id)
            ->assertJsonPath('data.direction', 'buy')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.currency_pair.symbol', 'EURUSD');

        $trade = Trade::firstOrFail();
        $this->assertSame('open', $trade->status);
        $this->assertNull($trade->resolved_at);
        $this->assertNull($trade->outcome);
        $this->assertNotNull($trade->opened_at);
        $this->assertEqualsWithDelta(100.0, (float) $trade->stake, 0.001);
    }

    public function test_opening_a_trade_writes_a_trade_stake_debit_via_ledger(): void
    {
        $user = User::factory()->create();
        $pair = CurrencyPair::factory()->create();
        $this->fundUser($user, 500.00);

        $this->authedFor($user)->postJson('/api/trades', [
            'currency_pair_id' => $pair->id,
            'direction' => 'sell',
            'stake' => 150.00,
        ])->assertCreated();

        $trade = Trade::firstOrFail();

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => 'trade_stake',
            'amount' => '-150.00',
            'reference_type' => $trade->getMorphClass(),
            'reference_id' => $trade->id,
        ]);

        $this->assertEqualsWithDelta(350.00, app(LedgerService::class)->balanceFor($user), 0.001);
    }

    public function test_open_trade_rejected_when_stake_exceeds_balance(): void
    {
        $user = User::factory()->create();
        $pair = CurrencyPair::factory()->create();
        $this->fundUser($user, 50.00);

        $response = $this->authedFor($user)->postJson('/api/trades', [
            'currency_pair_id' => $pair->id,
            'direction' => 'buy',
            'stake' => 100.00,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['stake']);

        $this->assertSame(0, Trade::count(), 'No trade should be created when stake is rejected');
        $this->assertSame(
            1,
            Transaction::where('user_id', $user->id)->count(),
            'Only the original funding credit should exist — no stake debit',
        );
        $this->assertEqualsWithDelta(50.00, app(LedgerService::class)->balanceFor($user), 0.001);
    }

    public function test_open_trade_rejected_with_zero_balance(): void
    {
        $user = User::factory()->create();
        $pair = CurrencyPair::factory()->create();

        $response = $this->authedFor($user)->postJson('/api/trades', [
            'currency_pair_id' => $pair->id,
            'direction' => 'buy',
            'stake' => 1.00,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['stake']);
        $this->assertSame(0, Trade::count());
    }

    public function test_open_trade_validates_direction(): void
    {
        $user = User::factory()->create();
        $pair = CurrencyPair::factory()->create();
        $this->fundUser($user, 1000);

        $this->authedFor($user)->postJson('/api/trades', [
            'currency_pair_id' => $pair->id,
            'direction' => 'long',
            'stake' => 10,
        ])->assertStatus(422)->assertJsonValidationErrors(['direction']);
    }

    public function test_open_trade_validates_stake_is_positive(): void
    {
        $user = User::factory()->create();
        $pair = CurrencyPair::factory()->create();
        $this->fundUser($user, 1000);

        $this->authedFor($user)->postJson('/api/trades', [
            'currency_pair_id' => $pair->id,
            'direction' => 'buy',
            'stake' => 0,
        ])->assertStatus(422)->assertJsonValidationErrors(['stake']);

        $this->authedFor($user)->postJson('/api/trades', [
            'currency_pair_id' => $pair->id,
            'direction' => 'buy',
            'stake' => -5,
        ])->assertStatus(422)->assertJsonValidationErrors(['stake']);
    }

    public function test_open_trade_rejects_unknown_pair(): void
    {
        $user = User::factory()->create();
        $this->fundUser($user, 1000);

        $this->authedFor($user)->postJson('/api/trades', [
            'currency_pair_id' => 99999,
            'direction' => 'buy',
            'stake' => 10,
        ])->assertStatus(422)->assertJsonValidationErrors(['currency_pair_id']);
    }

    public function test_open_trade_rejects_inactive_pair(): void
    {
        $user = User::factory()->create();
        $this->fundUser($user, 1000);
        $pair = CurrencyPair::factory()->inactive()->create();

        $this->authedFor($user)->postJson('/api/trades', [
            'currency_pair_id' => $pair->id,
            'direction' => 'buy',
            'stake' => 10,
        ])->assertStatus(422)->assertJsonValidationErrors(['currency_pair_id']);
    }

    public function test_open_trade_requires_authentication(): void
    {
        $pair = CurrencyPair::factory()->create();

        $this->postJson('/api/trades', [
            'currency_pair_id' => $pair->id,
            'direction' => 'buy',
            'stake' => 10,
        ])->assertStatus(401);
    }

    // -----------------------------------------------------------------
    // GET /api/trades
    // -----------------------------------------------------------------

    public function test_index_returns_only_the_authenticated_users_trades(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $pair = CurrencyPair::factory()->create();

        Trade::factory()->count(2)->create(['user_id' => $alice->id, 'currency_pair_id' => $pair->id]);
        Trade::factory()->count(3)->create(['user_id' => $bob->id, 'currency_pair_id' => $pair->id]);

        $response = $this->authedFor($alice)->getJson('/api/trades');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(2, $data);
        foreach ($data as $trade) {
            $this->assertSame($alice->id, $trade['user_id']);
        }
    }

    public function test_index_orders_newest_first(): void
    {
        $user = User::factory()->create();
        $pair = CurrencyPair::factory()->create();

        $oldest = Trade::factory()->create([
            'user_id' => $user->id,
            'currency_pair_id' => $pair->id,
            'created_at' => now()->subHours(3),
        ]);
        $middle = Trade::factory()->create([
            'user_id' => $user->id,
            'currency_pair_id' => $pair->id,
            'created_at' => now()->subHour(),
        ]);
        $newest = Trade::factory()->create([
            'user_id' => $user->id,
            'currency_pair_id' => $pair->id,
            'created_at' => now(),
        ]);

        $ids = array_column($this->authedFor($user)->getJson('/api/trades')->json('data'), 'id');

        $this->assertSame([$newest->id, $middle->id, $oldest->id], $ids);
    }

    public function test_index_can_filter_by_status(): void
    {
        $user = User::factory()->create();
        $pair = CurrencyPair::factory()->create();

        Trade::factory()->count(2)->create(['user_id' => $user->id, 'currency_pair_id' => $pair->id, 'status' => 'open']);
        Trade::factory()->closed()->count(3)->create(['user_id' => $user->id, 'currency_pair_id' => $pair->id]);

        $open = $this->authedFor($user)->getJson('/api/trades?status=open');
        $closed = $this->authedFor($user)->getJson('/api/trades?status=closed');

        $this->assertCount(2, $open->json('data'));
        $this->assertCount(3, $closed->json('data'));
        $this->assertEmpty(array_filter($open->json('data'), fn ($t) => $t['status'] !== 'open'));
        $this->assertEmpty(array_filter($closed->json('data'), fn ($t) => $t['status'] !== 'closed'));
    }

    public function test_index_rejects_unknown_status_filter(): void
    {
        $user = User::factory()->create();

        $this->authedFor($user)->getJson('/api/trades?status=bogus')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/trades')->assertStatus(401);
    }

    // -----------------------------------------------------------------
    // GET /api/trades/{id}
    // -----------------------------------------------------------------

    public function test_show_returns_users_own_trade_with_pnl_and_status(): void
    {
        $user = User::factory()->create();
        $pair = CurrencyPair::factory()->create();
        $trade = Trade::factory()->closed()->create([
            'user_id' => $user->id,
            'currency_pair_id' => $pair->id,
        ]);

        $response = $this->authedFor($user)->getJson("/api/trades/{$trade->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $trade->id)
            ->assertJsonPath('data.status', $trade->status)
            ->assertJsonPath('data.outcome', $trade->outcome)
            ->assertJsonStructure(['data' => ['id', 'status', 'pnl', 'currency_pair' => ['symbol']]]);
    }

    public function test_show_returns_404_when_trade_belongs_to_another_user(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $pair = CurrencyPair::factory()->create();
        $trade = Trade::factory()->create(['user_id' => $bob->id, 'currency_pair_id' => $pair->id]);

        $this->authedFor($alice)->getJson("/api/trades/{$trade->id}")
            ->assertStatus(404);
    }

    public function test_show_returns_404_for_nonexistent_trade(): void
    {
        $user = User::factory()->create();

        $this->authedFor($user)->getJson('/api/trades/99999')
            ->assertStatus(404);
    }

    public function test_show_requires_authentication(): void
    {
        $user = User::factory()->create();
        $pair = CurrencyPair::factory()->create();
        $trade = Trade::factory()->create(['user_id' => $user->id, 'currency_pair_id' => $pair->id]);

        $this->getJson("/api/trades/{$trade->id}")->assertStatus(401);
    }

    // -----------------------------------------------------------------
    // Resolution is admin-only — confirm no user-facing route allows it
    // -----------------------------------------------------------------

    public function test_no_user_facing_route_allows_resolving_a_trade(): void
    {
        $user = User::factory()->create();
        $pair = CurrencyPair::factory()->create();
        $trade = Trade::factory()->create(['user_id' => $user->id, 'currency_pair_id' => $pair->id]);

        // None of these verbs/paths should exist for regular users.
        $this->authedFor($user)->postJson("/api/trades/{$trade->id}/resolve", ['outcome' => 'win'])
            ->assertStatus(404);
        $this->authedFor($user)->patchJson("/api/trades/{$trade->id}", ['status' => 'closed', 'outcome' => 'win'])
            ->assertStatus(405);
        $this->authedFor($user)->putJson("/api/trades/{$trade->id}", ['status' => 'closed', 'outcome' => 'win'])
            ->assertStatus(405);

        $this->assertSame('open', $trade->fresh()->status, 'Trade status must remain open');
    }
}
