<?php

namespace Tests\Feature\Admin;

use App\Models\FundRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminFundRequestApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function authedAs(User $user): static
    {
        $token = $user->createToken('test')->plainTextToken;

        return $this->withHeader('Authorization', "Bearer $token");
    }

    // ---------------- Deposit approval ----------------

    public function test_admin_can_approve_a_deposit_and_credit_is_recorded(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $req = FundRequest::factory()->deposit()->create([
            'user_id' => $user->id,
            'amount' => 300.00,
            'status' => 'pending',
        ]);

        $response = $this->authedAs($admin)->postJson("/api/admin/fund-requests/{$req->id}/approve");

        $response->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.reviewed_by', $admin->id);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => 'admin_credit',
            'amount' => '300.00',
            'reference_type' => $req->getMorphClass(),
            'reference_id' => $req->id,
        ]);

        $this->assertEqualsWithDelta(300.0, app(LedgerService::class)->balanceFor($user), 0.001);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'fund_request.deposit.approved',
            'target_type' => $req->getMorphClass(),
            'target_id' => $req->id,
        ]);
    }

    // ---------------- Withdrawal approval ----------------

    public function test_admin_can_approve_a_withdrawal_and_debit_is_recorded(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        app(LedgerService::class)->credit($user, 'admin_credit', 500, 'seed');

        $req = FundRequest::factory()->withdrawal()->create([
            'user_id' => $user->id,
            'amount' => 200.00,
            'status' => 'pending',
        ]);

        $this->authedAs($admin)->postJson("/api/admin/fund-requests/{$req->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => 'admin_debit',
            'amount' => '-200.00',
            'reference_type' => $req->getMorphClass(),
            'reference_id' => $req->id,
        ]);

        $this->assertEqualsWithDelta(300.0, app(LedgerService::class)->balanceFor($user), 0.001);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'fund_request.withdrawal.approved',
            'target_id' => $req->id,
        ]);
    }

    public function test_withdrawal_approval_rechecks_balance_at_approval_time(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        app(LedgerService::class)->credit($user, 'admin_credit', 500, 'seed');

        $req = FundRequest::factory()->withdrawal()->create([
            'user_id' => $user->id,
            'amount' => 400.00,
            'status' => 'pending',
        ]);

        // User lost money on trades after filing the request.
        app(LedgerService::class)->debit($user, 'trade_stake', 450, 'lost on trades');

        $this->authedAs($admin)->postJson("/api/admin/fund-requests/{$req->id}/approve")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);

        $this->assertSame('pending', $req->fresh()->status);
    }

    // ---------------- Rejection ----------------

    public function test_admin_can_reject_a_request_and_balance_is_unchanged(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        app(LedgerService::class)->credit($user, 'admin_credit', 500, 'seed');

        $req = FundRequest::factory()->deposit()->create([
            'user_id' => $user->id,
            'amount' => 200,
            'status' => 'pending',
        ]);

        $response = $this->authedAs($admin)->postJson(
            "/api/admin/fund-requests/{$req->id}/reject",
            ['note' => 'documents missing'],
        );

        $response->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.note', 'documents missing');

        // Balance unchanged — still 500 (the original seed).
        $this->assertEqualsWithDelta(500.0, app(LedgerService::class)->balanceFor($user), 0.001);
        $this->assertSame(1, Transaction::where('user_id', $user->id)->count());

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'fund_request.deposit.rejected',
            'target_id' => $req->id,
        ]);
    }

    public function test_already_reviewed_request_cannot_be_re_approved(): void
    {
        $admin = User::factory()->admin()->create();
        $req = FundRequest::factory()->approved()->create();

        $this->authedAs($admin)->postJson("/api/admin/fund-requests/{$req->id}/approve")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    // ---------------- Index ----------------

    public function test_admin_index_returns_all_requests_with_user_balance_filterable(): void
    {
        $admin = User::factory()->admin()->create();
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        app(LedgerService::class)->credit($alice, 'admin_credit', 400, 'seed');

        FundRequest::factory()->deposit()->count(2)->create(['user_id' => $alice->id, 'status' => 'pending']);
        FundRequest::factory()->withdrawal()->create(['user_id' => $alice->id, 'status' => 'pending']);
        FundRequest::factory()->deposit()->approved()->create(['user_id' => $bob->id]);

        $all = $this->authedAs($admin)->getJson('/api/admin/fund-requests');
        $all->assertOk();
        $this->assertSame(4, $all->json('total'));

        // First row includes user.name and computed user_balance.
        $rows = $all->json('data');
        $this->assertArrayHasKey('user', $rows[0]);
        $this->assertArrayHasKey('name', $rows[0]['user']);
        $this->assertArrayHasKey('user_balance', $rows[0]);

        $pending = $this->authedAs($admin)->getJson('/api/admin/fund-requests?status=pending');
        $this->assertSame(3, $pending->json('total'));

        $deposits = $this->authedAs($admin)->getJson('/api/admin/fund-requests?type=deposit');
        $this->assertSame(3, $deposits->json('total'));
    }

    // ---------------- Authorization ----------------

    public function test_regular_user_cannot_call_admin_endpoints(): void
    {
        $user = User::factory()->create();
        $req = FundRequest::factory()->create(['user_id' => $user->id, 'status' => 'pending']);

        $this->authedAs($user)->getJson('/api/admin/fund-requests')->assertForbidden();
        $this->authedAs($user)->postJson("/api/admin/fund-requests/{$req->id}/approve")->assertForbidden();
        $this->authedAs($user)->postJson("/api/admin/fund-requests/{$req->id}/reject")->assertForbidden();
    }

    public function test_admin_endpoints_require_authentication(): void
    {
        $this->getJson('/api/admin/fund-requests')->assertStatus(401);
    }
}
