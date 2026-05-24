<?php

namespace Tests\Feature\Admin;

use App\Models\DepositRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function authedAs(User $user): static
    {
        $token = $user->createToken('test')->plainTextToken;

        return $this->withHeader('Authorization', "Bearer $token");
    }

    // ---------------- Deposits ----------------

    public function test_admin_can_approve_a_deposit_request_and_credits_balance(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $deposit = DepositRequest::factory()->create([
            'user_id' => $user->id,
            'amount' => 300.00,
            'status' => 'pending',
        ]);

        $response = $this->authedAs($admin)->postJson(
            "/api/admin/deposits/{$deposit->id}/approve",
            ['note' => 'wire received'],
        );

        $response->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.reviewed_by', $admin->id);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => 'deposit_approved',
            'amount' => '300.00',
            'reference_type' => $deposit->getMorphClass(),
            'reference_id' => $deposit->id,
        ]);

        $this->assertEqualsWithDelta(300.0, app(LedgerService::class)->balanceFor($user), 0.001);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'deposit.approved',
            'target_type' => $deposit->getMorphClass(),
            'target_id' => $deposit->id,
        ]);
    }

    public function test_admin_can_reject_a_deposit_without_moving_money(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $deposit = DepositRequest::factory()->create([
            'user_id' => $user->id,
            'amount' => 200,
            'status' => 'pending',
        ]);

        $response = $this->authedAs($admin)->postJson(
            "/api/admin/deposits/{$deposit->id}/reject",
            ['note' => 'docs missing'],
        );

        $response->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertSame(0, Transaction::where('user_id', $user->id)->count());

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'deposit.rejected',
            'target_id' => $deposit->id,
        ]);
    }

    public function test_already_reviewed_deposit_cannot_be_re_approved(): void
    {
        $admin = User::factory()->admin()->create();
        $deposit = DepositRequest::factory()->approved()->create();

        $this->authedAs($admin)->postJson("/api/admin/deposits/{$deposit->id}/approve")
            ->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    // ---------------- Withdrawals ----------------

    public function test_admin_can_approve_a_withdrawal_and_debits_balance(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        app(LedgerService::class)->credit($user, 'admin_credit', 500, 'seed');

        $withdrawal = WithdrawalRequest::factory()->create([
            'user_id' => $user->id,
            'amount' => 200,
            'status' => 'pending',
        ]);

        $this->authedAs($admin)->postJson("/api/admin/withdrawals/{$withdrawal->id}/approve")
            ->assertOk()->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => 'withdrawal_approved',
            'amount' => '-200.00',
            'reference_type' => $withdrawal->getMorphClass(),
            'reference_id' => $withdrawal->id,
        ]);

        $this->assertEqualsWithDelta(300.0, app(LedgerService::class)->balanceFor($user), 0.001);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'withdrawal.approved',
            'target_id' => $withdrawal->id,
        ]);
    }

    public function test_approve_withdrawal_rechecks_balance_at_approval_time(): void
    {
        // User filed a withdrawal request, then lost most of their money
        // (e.g. on trades) before admin reviewed it. Approval must fail.
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        app(LedgerService::class)->credit($user, 'admin_credit', 500, 'seed');

        $withdrawal = WithdrawalRequest::factory()->create([
            'user_id' => $user->id,
            'amount' => 400,
            'status' => 'pending',
        ]);

        // Simulate balance dropping after the request was filed
        app(LedgerService::class)->debit($user, 'trade_stake', 450, 'lost on trades');

        $this->authedAs($admin)->postJson("/api/admin/withdrawals/{$withdrawal->id}/approve")
            ->assertStatus(422)->assertJsonValidationErrors(['amount']);

        $this->assertSame('pending', $withdrawal->fresh()->status);
    }

    public function test_admin_can_reject_a_withdrawal(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        app(LedgerService::class)->credit($user, 'admin_credit', 500, 'seed');

        $withdrawal = WithdrawalRequest::factory()->create([
            'user_id' => $user->id,
            'amount' => 100,
            'status' => 'pending',
        ]);

        $this->authedAs($admin)->postJson("/api/admin/withdrawals/{$withdrawal->id}/reject", ['note' => 'AML hold'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');

        // Balance untouched (no withdrawal_approved transaction)
        $this->assertSame(
            1,
            Transaction::where('user_id', $user->id)->count(),
            'Only the original funding credit should exist',
        );

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'withdrawal.rejected',
            'target_id' => $withdrawal->id,
        ]);
    }

    public function test_regular_user_cannot_call_approval_endpoints(): void
    {
        $user = User::factory()->create();
        $deposit = DepositRequest::factory()->create(['user_id' => $user->id, 'status' => 'pending']);
        $withdrawal = WithdrawalRequest::factory()->create(['user_id' => $user->id, 'status' => 'pending']);

        $this->authedAs($user)->postJson("/api/admin/deposits/{$deposit->id}/approve")->assertForbidden();
        $this->authedAs($user)->postJson("/api/admin/deposits/{$deposit->id}/reject")->assertForbidden();
        $this->authedAs($user)->postJson("/api/admin/withdrawals/{$withdrawal->id}/approve")->assertForbidden();
        $this->authedAs($user)->postJson("/api/admin/withdrawals/{$withdrawal->id}/reject")->assertForbidden();
    }
}
