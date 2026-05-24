<?php

namespace Tests\Feature;

use App\Models\CurrencyPair;
use App\Models\Trade;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * End-to-end audit of the trading flow: open -> resolve.
 * Asserts the invariants from the hardening spec — direction never
 * silently changes, balance always equals sum(transactions), no path
 * leaves a user with a negative balance.
 */
class TradingFlowTest extends TestCase
{
    use RefreshDatabase;

    private function authedFor(User $user): static
    {
        $token = $user->createToken('test')->plainTextToken;
        return $this->withHeader('Authorization', "Bearer $token");
    }

    private function ledger(): LedgerService
    {
        return app(LedgerService::class);
    }

    private function fund(User $user, float $amount): void
    {
        $this->ledger()->credit($user, 'admin_credit', $amount, 'test funding');
    }

    private function assertBalanceEqualsSum(User $user, float $expected): void
    {
        $sum = (float) Transaction::where('user_id', $user->id)->sum('amount');
        $balance = $this->ledger()->balanceFor($user);

        $this->assertEqualsWithDelta($expected, $sum, 0.001, 'sum(transactions) mismatch');
        $this->assertEqualsWithDelta($expected, $balance, 0.001, 'LedgerService::balanceFor mismatch');
        $this->assertGreaterThanOrEqual(0, $balance, 'balance went negative');
    }

    // ============================================================
    // OPEN-TRADE HARDENING
    // ============================================================

    public function test_open_response_returns_post_debit_balance(): void
    {
        $user = User::factory()->create();
        $pair = CurrencyPair::factory()->create();
        $this->fund($user, 500);

        $response = $this->authedFor($user)->postJson('/api/trades', [
            'currency_pair_id' => $pair->id,
            'direction' => 'buy',
            'stake' => 120.00,
        ]);

        $response->assertCreated();

        // Response carries the freshly-debited balance — no /me round-trip needed.
        $this->assertEqualsWithDelta(380.0, (float) $response->json('balance'), 0.001);
        $this->assertEqualsWithDelta(380.0, (float) $response->json('available_balance'), 0.001);
        $this->assertEqualsWithDelta(380.0, $this->ledger()->balanceFor($user), 0.001);
    }

    public function test_open_with_stake_larger_than_balance_is_rejected_and_no_writes_happen(): void
    {
        $user = User::factory()->create();
        $pair = CurrencyPair::factory()->create();
        $this->fund($user, 100);

        $response = $this->authedFor($user)->postJson('/api/trades', [
            'currency_pair_id' => $pair->id,
            'direction' => 'buy',
            'stake' => 200.00,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['stake']);

        $this->assertSame(0, Trade::count(), 'no trade row should exist');
        $this->assertSame(
            1,
            Transaction::where('user_id', $user->id)->count(),
            'only the original funding credit should exist',
        );
        $this->assertBalanceEqualsSum($user, 100.0);
    }

    public function test_direction_is_persisted_exactly_as_submitted_for_buy_and_sell(): void
    {
        $user = User::factory()->create();
        $pair = CurrencyPair::factory()->create();
        $this->fund($user, 1000);

        $buy = $this->authedFor($user)->postJson('/api/trades', [
            'currency_pair_id' => $pair->id,
            'direction' => 'buy',
            'stake' => 50,
        ])->assertCreated();
        $this->assertSame('buy', $buy->json('data.direction'));
        $this->assertSame('buy', Trade::find($buy->json('data.id'))->direction);

        Auth::forgetGuards();

        $sell = $this->authedFor($user)->postJson('/api/trades', [
            'currency_pair_id' => $pair->id,
            'direction' => 'sell',
            'stake' => 50,
        ])->assertCreated();
        $this->assertSame('sell', $sell->json('data.direction'));
        $this->assertSame('sell', Trade::find($sell->json('data.id'))->direction);

        Auth::forgetGuards();

        // Index lists both with the right directions
        $list = $this->authedFor($user)->getJson('/api/trades')->json('data');
        $byId = collect($list)->keyBy('id');
        $this->assertSame('sell', $byId[$sell->json('data.id')]['direction']);
        $this->assertSame('buy', $byId[$buy->json('data.id')]['direction']);
    }

    // ============================================================
    // FULL HAPPY PATH × 4 (buy/sell × win/loss)
    // ============================================================

    public function test_buy_win_full_happy_path(): void
    {
        $this->assertFullHappyPath(direction: 'buy', outcome: 'win', stake: 100, pnl: 40);
        // 500 - 100 stake + (100 stake + 40 profit) = 540
    }

    public function test_buy_loss_full_happy_path(): void
    {
        $this->assertFullHappyPath(direction: 'buy', outcome: 'loss', stake: 100, pnl: 30);
        // 500 - 100 stake + (100 stake - 30 loss) = 470
    }

    public function test_sell_win_full_happy_path(): void
    {
        $this->assertFullHappyPath(direction: 'sell', outcome: 'win', stake: 100, pnl: 25);
        // 500 - 100 stake + 125 payout = 525
    }

    public function test_sell_loss_full_happy_path(): void
    {
        $this->assertFullHappyPath(direction: 'sell', outcome: 'loss', stake: 100, pnl: 60);
        // 500 - 100 stake + (100 - 60) = 440
    }

    private function assertFullHappyPath(string $direction, string $outcome, float $stake, float $pnl): void
    {
        // 1. Fund
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $pair = CurrencyPair::factory()->create(['symbol' => 'EURUSD']);
        $this->fund($user, 500);

        // 2. Open trade — stake debited as a single trade_stake transaction
        Auth::forgetGuards();
        $open = $this->authedFor($user)->postJson('/api/trades', [
            'currency_pair_id' => $pair->id,
            'direction' => $direction,
            'stake' => $stake,
        ]);
        $open->assertCreated()
            ->assertJsonPath('data.direction', $direction)
            ->assertJsonPath('data.status', 'open');

        $tradeId = $open->json('data.id');
        $trade = Trade::findOrFail($tradeId);

        $this->assertSame(
            1,
            Transaction::where('user_id', $user->id)
                ->where('type', 'trade_stake')
                ->where('reference_type', $trade->getMorphClass())
                ->where('reference_id', $tradeId)
                ->count(),
            'exactly one trade_stake transaction must exist for this trade',
        );

        $this->assertEqualsWithDelta(500 - $stake, (float) $open->json('balance'), 0.001);
        $this->assertBalanceEqualsSum($user, 500 - $stake);

        // 3. Admin resolves
        Auth::forgetGuards();
        $resolve = $this->authedFor($admin)->postJson("/api/admin/trades/{$tradeId}/resolve", [
            'outcome' => $outcome,
            'pnl' => $pnl,
        ]);
        $resolve->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.direction', $direction)
            ->assertJsonPath('data.outcome', $outcome);

        // Signed P&L stored
        $expectedSignedPnl = $outcome === 'win' ? $pnl : -$pnl;
        $this->assertEqualsWithDelta($expectedSignedPnl, (float) $resolve->json('data.pnl'), 0.001);

        // 4. Payout = stake ± pnl
        $payout = $outcome === 'win' ? $stake + $pnl : $stake - $pnl;
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => 'trade_payout',
            'amount' => number_format($payout, 2, '.', ''),
            'reference_type' => $trade->getMorphClass(),
            'reference_id' => $tradeId,
        ]);

        // 5. Final balance: 500 - stake + payout
        $expectedFinal = 500 - $stake + $payout;
        $this->assertBalanceEqualsSum($user, $expectedFinal);

        // 6. Disappears from /api/trades?status=open
        Auth::forgetGuards();
        $openList = $this->authedFor($user)->getJson('/api/trades?status=open')->json('data');
        $this->assertEmpty(
            array_filter($openList, fn ($t) => $t['id'] === $tradeId),
            'closed trade must not appear in Open Trades list',
        );

        // 7. Shows up in closed with correct outcome + signed pnl
        Auth::forgetGuards();
        $closedList = $this->authedFor($user)->getJson('/api/trades?status=closed')->json('data');
        $closedRow = collect($closedList)->firstWhere('id', $tradeId);
        $this->assertNotNull($closedRow);
        $this->assertSame($outcome, $closedRow['outcome']);
        $this->assertEqualsWithDelta($expectedSignedPnl, (float) $closedRow['pnl'], 0.001);
    }

    // ============================================================
    // FULL LOSS — balance floors at 0, never negative
    // ============================================================

    public function test_full_loss_leaves_balance_at_exactly_zero_not_negative(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $pair = CurrencyPair::factory()->create();
        $this->fund($user, 100);

        Auth::forgetGuards();
        $open = $this->authedFor($user)->postJson('/api/trades', [
            'currency_pair_id' => $pair->id,
            'direction' => 'buy',
            'stake' => 100,
        ])->assertCreated();
        $tradeId = $open->json('data.id');

        $this->assertEqualsWithDelta(0.0, $this->ledger()->balanceFor($user), 0.001);

        Auth::forgetGuards();
        $this->authedFor($admin)->postJson("/api/admin/trades/{$tradeId}/resolve", [
            'outcome' => 'loss',
            'pnl' => 100, // full loss
        ])->assertOk()->assertJsonPath('data.status', 'closed');

        // Stake (100) was debited at open, full loss yields payout 0.
        // No trade_payout transaction is written, balance stays at 0.
        $this->assertSame(
            0,
            Transaction::where('user_id', $user->id)
                ->where('type', 'trade_payout')
                ->count(),
            'no trade_payout should be written when payout = 0',
        );
        $this->assertBalanceEqualsSum($user, 0.0);
    }

    public function test_loss_amount_cannot_exceed_stake_so_payout_never_goes_negative(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $pair = CurrencyPair::factory()->create();
        $this->fund($user, 1000);

        Auth::forgetGuards();
        $open = $this->authedFor($user)->postJson('/api/trades', [
            'currency_pair_id' => $pair->id,
            'direction' => 'buy',
            'stake' => 100,
        ])->assertCreated();
        $tradeId = $open->json('data.id');

        Auth::forgetGuards();
        $this->authedFor($admin)->postJson("/api/admin/trades/{$tradeId}/resolve", [
            'outcome' => 'loss',
            'pnl' => 200, // > stake
        ])->assertStatus(422)->assertJsonValidationErrors(['pnl']);

        // Trade still open, balance still 900.
        $this->assertSame('open', Trade::find($tradeId)->status);
        $this->assertBalanceEqualsSum($user, 900.0);
    }

    // ============================================================
    // AUTHORIZATION — users can't see/resolve others' trades, can't
    // reach the admin resolve endpoint at all.
    // ============================================================

    public function test_user_can_only_see_their_own_trades(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $pair = CurrencyPair::factory()->create();
        $bobsTrade = Trade::factory()->create([
            'user_id' => $bob->id,
            'currency_pair_id' => $pair->id,
        ]);

        // Alice's index doesn't include Bob's trade
        $list = $this->authedFor($alice)->getJson('/api/trades')->json('data');
        $this->assertEmpty(array_filter($list, fn ($t) => $t['id'] === $bobsTrade->id));

        // Alice can't fetch Bob's trade directly
        $this->authedFor($alice)->getJson("/api/trades/{$bobsTrade->id}")
            ->assertStatus(404);
    }

    public function test_user_can_never_resolve_a_trade(): void
    {
        $user = User::factory()->create();
        $pair = CurrencyPair::factory()->create();
        $trade = Trade::factory()->create([
            'user_id' => $user->id,
            'currency_pair_id' => $pair->id,
            'status' => 'open',
        ]);

        // No user-facing resolve endpoint exists
        $this->authedFor($user)->postJson("/api/trades/{$trade->id}/resolve", ['outcome' => 'win', 'pnl' => 50])
            ->assertStatus(404);

        // Admin route exists but is admin-gated → 403 for non-admin
        $this->authedFor($user)->postJson("/api/admin/trades/{$trade->id}/resolve", ['outcome' => 'win', 'pnl' => 50])
            ->assertForbidden();

        $this->assertSame('open', $trade->fresh()->status);
    }
}
