<?php

namespace Tests\Feature;

use App\Models\CurrencyPair;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * End-to-end happy path:
 *   register → admin credits $100 → user opens $50 trade
 *   → admin resolves it as a win with $30 profit → balance reflects 50 + 80 = 130.
 */
class HappyPathE2ETest extends TestCase
{
    use RefreshDatabase;

    public function test_full_happy_path_register_credit_trade_resolve(): void
    {
        // ---- ARRANGE: admin + at least one tradeable pair must exist ----

        $admin = User::factory()->admin()->create([
            'email' => 'admin@example.com',
            'password' => Hash::make('adminpass'),
        ]);
        $adminToken = $admin->createToken('e2e-admin')->plainTextToken;

        $pair = CurrencyPair::factory()->create(['symbol' => 'EURUSD', 'base' => 'EUR', 'quote' => 'USD']);

        // ---- STEP 1: a new user registers ----
        $register = $this->postJson('/api/register', [
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'password123',
        ]);
        $register->assertCreated()->assertJsonStructure(['user' => ['id'], 'token']);

        $userId = $register->json('user.id');
        $userToken = $register->json('token');

        // Balance starts at 0
        Auth::forgetGuards();
        $this->assertEqualsWithDelta(
            0.0,
            (float) $this->withHeader('Authorization', "Bearer $userToken")->getJson('/api/me')->json('balance'),
            0.001,
        );

        // ---- STEP 2: admin credits the user $100 ----
        Auth::forgetGuards();
        $credit = $this->withHeader('Authorization', "Bearer $adminToken")->postJson(
            "/api/admin/users/{$userId}/adjust-balance",
            ['direction' => 'credit', 'amount' => 100, 'note' => 'Welcome bonus'],
        );
        $credit->assertOk();

        // User now sees $100
        Auth::forgetGuards();
        $this->assertEqualsWithDelta(
            100.0,
            (float) $this->withHeader('Authorization', "Bearer $userToken")->getJson('/api/me')->json('balance'),
            0.001,
        );

        // ---- STEP 3: user opens a $50 BUY trade on EURUSD ----
        Auth::forgetGuards();
        $openTrade = $this->withHeader('Authorization', "Bearer $userToken")->postJson('/api/trades', [
            'currency_pair_id' => $pair->id,
            'direction' => 'buy',
            'stake' => 50,
        ]);
        $openTrade->assertCreated()->assertJsonPath('data.status', 'open');
        $tradeId = $openTrade->json('data.id');

        // Stake debited at open time → balance is now $50
        Auth::forgetGuards();
        $this->assertEqualsWithDelta(
            50.0,
            (float) $this->withHeader('Authorization', "Bearer $userToken")->getJson('/api/me')->json('balance'),
            0.001,
        );

        // ---- STEP 4: admin resolves the trade as a win with $30 profit ----
        Auth::forgetGuards();
        $resolve = $this->withHeader('Authorization', "Bearer $adminToken")->postJson(
            "/api/admin/trades/{$tradeId}/resolve",
            ['outcome' => 'win', 'pnl' => 30],
        );
        $resolve->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.outcome', 'win');

        // ---- STEP 5: user balance reflects the win ----
        // 100 (credit) - 50 (stake) + 80 (payout = stake 50 + profit 30) = 130
        Auth::forgetGuards();
        $finalBalance = (float) $this->withHeader('Authorization', "Bearer $userToken")
            ->getJson('/api/me')->json('balance');
        $this->assertEqualsWithDelta(130.0, $finalBalance, 0.001);

        // Independent verification through the LedgerService — sum equals balance.
        $ledgerBalance = app(LedgerService::class)->balanceFor(User::findOrFail($userId));
        $this->assertEqualsWithDelta(130.0, $ledgerBalance, 0.001);

        // ---- AUDIT TRAIL: every admin money action recorded ----
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'user.balance.credit',
            'target_id' => $userId,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'trade.resolved',
            'target_id' => $tradeId,
        ]);

        // Expected transactions: admin_credit, trade_stake (-50), trade_payout (+80)
        $this->assertDatabaseHas('transactions', ['user_id' => $userId, 'type' => 'admin_credit', 'amount' => '100.00']);
        $this->assertDatabaseHas('transactions', ['user_id' => $userId, 'type' => 'trade_stake', 'amount' => '-50.00']);
        $this->assertDatabaseHas('transactions', ['user_id' => $userId, 'type' => 'trade_payout', 'amount' => '80.00']);
    }
}
