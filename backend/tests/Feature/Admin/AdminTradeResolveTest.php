<?php

namespace Tests\Feature\Admin;

use App\Models\CurrencyPair;
use App\Models\Trade;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTradeResolveTest extends TestCase
{
    use RefreshDatabase;

    private function authedAs(User $user): static
    {
        $token = $user->createToken('test')->plainTextToken;

        return $this->withHeader('Authorization', "Bearer $token");
    }

    /**
     * Set up a user with a funded balance and one open trade with `stake` debited.
     *
     * @return array{0: User, 1: Trade, 2: User}
     */
    private function openTradeFor(float $funding, float $stake): array
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $pair = CurrencyPair::factory()->create(['symbol' => 'EURUSD']);

        app(LedgerService::class)->credit($user, 'admin_credit', $funding, 'seed');

        $trade = Trade::create([
            'user_id' => $user->id,
            'currency_pair_id' => $pair->id,
            'direction' => 'buy',
            'stake' => $stake,
            'status' => 'open',
            'opened_at' => now(),
        ]);
        app(LedgerService::class)->debit($user, 'trade_stake', $stake, "Trade #{$trade->id}", $trade);

        return [$admin, $trade, $user];
    }

    public function test_admin_can_resolve_a_winning_trade(): void
    {
        // Funded 500, staked 100 → balance 400 before resolve
        [$admin, $trade, $user] = $this->openTradeFor(500, 100);

        $this->assertEqualsWithDelta(400.0, app(LedgerService::class)->balanceFor($user), 0.001);

        $response = $this->authedAs($admin)->postJson("/api/admin/trades/{$trade->id}/resolve", [
            'outcome' => 'win',
            'pnl' => 80.00,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.outcome', 'win')
            ->assertJsonPath('data.pnl', '80.00')
            ->assertJsonPath('data.resolved_by', $admin->id);

        $trade->refresh();
        $this->assertNotNull($trade->resolved_at);

        // trade_payout = stake (100) + profit (80) = 180
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => 'trade_payout',
            'amount' => '180.00',
            'reference_type' => $trade->getMorphClass(),
            'reference_id' => $trade->id,
        ]);

        // Balance: 500 (seed) - 100 (stake debit) + 180 (payout) = 580
        $this->assertEqualsWithDelta(580.0, app(LedgerService::class)->balanceFor($user), 0.001);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'trade.resolved',
            'target_type' => $trade->getMorphClass(),
            'target_id' => $trade->id,
        ]);
    }

    public function test_admin_can_resolve_a_losing_trade(): void
    {
        // Funded 500, staked 100 → balance 400 before resolve
        [$admin, $trade, $user] = $this->openTradeFor(500, 100);

        $response = $this->authedAs($admin)->postJson("/api/admin/trades/{$trade->id}/resolve", [
            'outcome' => 'loss',
            'pnl' => 30.00,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.outcome', 'loss')
            ->assertJsonPath('data.pnl', '-30.00');

        // trade_payout = stake (100) - loss (30) = 70 (partial refund)
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => 'trade_payout',
            'amount' => '70.00',
            'reference_type' => $trade->getMorphClass(),
            'reference_id' => $trade->id,
        ]);

        // Balance: 500 - 100 + 70 = 470
        $this->assertEqualsWithDelta(470.0, app(LedgerService::class)->balanceFor($user), 0.001);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'trade.resolved',
            'target_id' => $trade->id,
        ]);
    }

    public function test_full_loss_writes_no_payout_transaction_but_still_closes_trade(): void
    {
        [$admin, $trade, $user] = $this->openTradeFor(500, 100);

        $this->authedAs($admin)->postJson("/api/admin/trades/{$trade->id}/resolve", [
            'outcome' => 'loss',
            'pnl' => 100.00,
        ])->assertOk()->assertJsonPath('data.status', 'closed');

        // No payout transaction — only the original credit + stake debit.
        $this->assertSame(2, Transaction::where('user_id', $user->id)->count());
        $this->assertEqualsWithDelta(400.0, app(LedgerService::class)->balanceFor($user), 0.001);
    }

    public function test_loss_amount_cannot_exceed_stake(): void
    {
        [$admin, $trade, $user] = $this->openTradeFor(500, 100);

        $this->authedAs($admin)->postJson("/api/admin/trades/{$trade->id}/resolve", [
            'outcome' => 'loss',
            'pnl' => 200.00,
        ])->assertStatus(422)->assertJsonValidationErrors(['pnl']);

        $this->assertSame('open', $trade->fresh()->status);
    }

    public function test_resolving_an_already_resolved_trade_returns_422(): void
    {
        [$admin, $trade, $user] = $this->openTradeFor(500, 100);

        $this->authedAs($admin)->postJson("/api/admin/trades/{$trade->id}/resolve", [
            'outcome' => 'win',
            'pnl' => 50,
        ])->assertOk();

        $this->authedAs($admin)->postJson("/api/admin/trades/{$trade->id}/resolve", [
            'outcome' => 'win',
            'pnl' => 50,
        ])->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    public function test_resolve_validates_outcome_and_pnl(): void
    {
        [$admin, $trade] = $this->openTradeFor(500, 100);

        $this->authedAs($admin)->postJson("/api/admin/trades/{$trade->id}/resolve", [
            'outcome' => 'draw',
            'pnl' => 10,
        ])->assertStatus(422)->assertJsonValidationErrors(['outcome']);

        $this->authedAs($admin)->postJson("/api/admin/trades/{$trade->id}/resolve", [
            'outcome' => 'win',
            'pnl' => 0,
        ])->assertStatus(422)->assertJsonValidationErrors(['pnl']);
    }

    public function test_regular_user_cannot_resolve_any_trade(): void
    {
        [$admin, $trade, $user] = $this->openTradeFor(500, 100);

        $this->authedAs($user)->postJson("/api/admin/trades/{$trade->id}/resolve", [
            'outcome' => 'win',
            'pnl' => 50,
        ])->assertForbidden();

        $this->assertSame('open', $trade->fresh()->status);
    }

    public function test_admin_trade_index_returns_all_users_trades_filterable(): void
    {
        $admin = User::factory()->admin()->create();
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $pair = CurrencyPair::factory()->create();

        Trade::factory()->count(2)->create(['user_id' => $alice->id, 'currency_pair_id' => $pair->id, 'status' => 'open']);
        Trade::factory()->closed()->count(3)->create(['user_id' => $bob->id, 'currency_pair_id' => $pair->id]);

        $all = $this->authedAs($admin)->getJson('/api/admin/trades');
        $all->assertOk();
        $this->assertSame(5, $all->json('total'));

        $openOnly = $this->authedAs($admin)->getJson('/api/admin/trades?status=open');
        $this->assertSame(2, $openOnly->json('total'));

        $alicesOnly = $this->authedAs($admin)->getJson("/api/admin/trades?user_id={$alice->id}");
        $this->assertSame(2, $alicesOnly->json('total'));
    }
}
