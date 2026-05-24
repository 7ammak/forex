<?php

namespace Tests\Feature;

use App\Models\CurrencyPair;
use App\Models\Trade;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class LedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    private LedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = app(LedgerService::class);
    }

    public function test_credit_inserts_a_positive_transaction(): void
    {
        $user = User::factory()->create();

        $tx = $this->ledger->credit($user, 'admin_credit', 100.0, 'initial deposit');

        $this->assertEquals(100.00, (float) $tx->amount);
        $this->assertSame('admin_credit', $tx->type);
        $this->assertSame('initial deposit', $tx->note);
        $this->assertSame(100.00, $this->ledger->balanceFor($user));
    }

    public function test_debit_inserts_a_negative_transaction(): void
    {
        $user = User::factory()->create();
        $this->ledger->credit($user, 'admin_credit', 500.0, null);

        $tx = $this->ledger->debit($user, 'trade_stake', 75.50, 'stake');

        $this->assertEquals(-75.50, (float) $tx->amount);
        $this->assertSame('trade_stake', $tx->type);
        $this->assertEqualsWithDelta(424.50, $this->ledger->balanceFor($user), 0.001);
    }

    public function test_balance_for_returns_sum_of_only_that_users_transactions(): void
    {
        $u1 = User::factory()->create();
        $u2 = User::factory()->create();

        $this->ledger->credit($u1, 'admin_credit', 100.0, null);
        $this->ledger->credit($u1, 'deposit_approved', 50.0, null);
        $this->ledger->debit($u1, 'trade_stake', 20.0, null);

        $this->ledger->credit($u2, 'admin_credit', 999.0, null);

        $this->assertSame(130.00, $this->ledger->balanceFor($u1));
        $this->assertSame(999.00, $this->ledger->balanceFor($u2));
    }

    public function test_available_balance_equals_balance(): void
    {
        $user = User::factory()->create();
        $this->ledger->credit($user, 'admin_credit', 200.0, null);
        $this->ledger->debit($user, 'trade_stake', 80.0, null);

        $this->assertSame(
            $this->ledger->balanceFor($user),
            $this->ledger->availableBalance($user),
        );
    }

    public function test_balance_always_equals_sum_of_transactions_after_many_writes(): void
    {
        $user = User::factory()->create();
        $expected = 0.0;

        for ($i = 0; $i < 200; $i++) {
            $amount = round(mt_rand(100, 10_000) / 100, 2);
            if (mt_rand(0, 1) === 0) {
                $this->ledger->credit($user, 'admin_credit', $amount, null);
                $expected += $amount;
            } else {
                $this->ledger->debit($user, 'admin_debit', $amount, null);
                $expected -= $amount;
            }
        }

        $rawSum = (float) Transaction::where('user_id', $user->id)->sum('amount');

        $this->assertEqualsWithDelta($expected, $rawSum, 0.001);
        $this->assertEqualsWithDelta($rawSum, $this->ledger->balanceFor($user), 0.001);
        $this->assertSame(200, Transaction::where('user_id', $user->id)->count());
    }

    public function test_amount_must_be_positive(): void
    {
        $user = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->ledger->credit($user, 'admin_credit', 0, null);
    }

    public function test_negative_amount_is_rejected_on_debit(): void
    {
        $user = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->ledger->debit($user, 'admin_debit', -5, null);
    }

    public function test_writes_run_inside_a_db_transaction(): void
    {
        $user = User::factory()->create();
        $insideTransaction = null;

        DB::listen(function ($query) use (&$insideTransaction) {
            if ($insideTransaction === null
                && str_starts_with(strtolower($query->sql), 'insert into "transactions"')
            ) {
                $insideTransaction = DB::transactionLevel() > 0;
            }
        });

        $this->ledger->credit($user, 'admin_credit', 100.0, null);

        $this->assertTrue($insideTransaction, 'INSERT into transactions must run inside a DB transaction');
    }

    public function test_users_row_is_locked_before_inserting_transaction(): void
    {
        $user = User::factory()->create();
        $queries = [];

        DB::listen(function ($query) use (&$queries) {
            $queries[] = strtolower($query->sql);
        });

        $this->ledger->credit($user, 'admin_credit', 100.0, null);

        $lockSelectIdx = null;
        $insertIdx = null;
        foreach ($queries as $i => $sql) {
            if ($lockSelectIdx === null
                && str_starts_with($sql, 'select')
                && str_contains($sql, 'from "users"')
                && str_contains($sql, '"users"."id"')
            ) {
                $lockSelectIdx = $i;
            }
            if ($insertIdx === null && str_starts_with($sql, 'insert into "transactions"')) {
                $insertIdx = $i;
            }
        }

        $this->assertNotNull($lockSelectIdx, 'Expected a SELECT against users (the lockForUpdate query)');
        $this->assertNotNull($insertIdx, 'Expected an INSERT into transactions');
        $this->assertLessThan($insertIdx, $lockSelectIdx, 'User row must be locked before the transaction insert');
    }

    public function test_reference_is_recorded_polymorphically(): void
    {
        $user = User::factory()->create();
        $pair = CurrencyPair::factory()->create();
        $trade = Trade::create([
            'user_id' => $user->id,
            'currency_pair_id' => $pair->id,
            'direction' => 'buy',
            'stake' => 100,
            'opened_at' => now(),
        ]);

        $tx = $this->ledger->debit($user, 'trade_stake', 100.0, null, $trade);

        $this->assertSame($trade->getMorphClass(), $tx->reference_type);
        $this->assertSame($trade->id, (int) $tx->reference_id);
        $this->assertTrue($tx->reference->is($trade));
    }
}
