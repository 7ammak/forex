<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function authedAs(User $user): static
    {
        $token = $user->createToken('test')->plainTextToken;

        return $this->withHeader('Authorization', "Bearer $token");
    }

    public function test_admin_can_list_users_with_balance_and_search(): void
    {
        $admin = User::factory()->admin()->create();
        $alice = User::factory()->create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $bob = User::factory()->create(['name' => 'Bob', 'email' => 'bob@example.com']);

        app(LedgerService::class)->credit($alice, 'admin_credit', 500, 'seed');
        app(LedgerService::class)->debit($alice, 'admin_debit', 75, 'fee');
        app(LedgerService::class)->credit($bob, 'admin_credit', 1000, 'seed');

        $response = $this->authedAs($admin)->getJson('/api/admin/users');
        $response->assertOk()->assertJsonStructure(['data', 'current_page', 'per_page', 'total']);

        $byId = collect($response->json('data'))->keyBy('id');
        $this->assertEqualsWithDelta(425.0, $byId[$alice->id]['balance'], 0.001);
        $this->assertEqualsWithDelta(1000.0, $byId[$bob->id]['balance'], 0.001);
        $this->assertEqualsWithDelta(0.0, $byId[$admin->id]['balance'], 0.001);

        // Search by email substring
        $search = $this->authedAs($admin)->getJson('/api/admin/users?search=alice');
        $search->assertOk();
        $rows = $search->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame($alice->id, $rows[0]['id']);
    }

    public function test_admin_can_suspend_and_reactivate_a_user_and_writes_audit_log(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create();
        $target->createToken('phone'); // exists so we can assert it's revoked on suspend

        $response = $this->authedAs($admin)->patchJson("/api/admin/users/{$target->id}", [
            'status' => 'suspended',
        ]);

        $response->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->assertSame('suspended', $target->fresh()->status);

        // Tokens revoked on suspend
        $this->assertSame(0, $target->fresh()->tokens()->count());

        // Audit log written
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'user.suspended',
            'target_type' => $target->getMorphClass(),
            'target_id' => $target->id,
        ]);

        // Reactivate
        $this->authedAs($admin)->patchJson("/api/admin/users/{$target->id}", ['status' => 'active'])
            ->assertOk()->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'user.reactivated',
            'target_id' => $target->id,
        ]);
    }

    public function test_admin_can_credit_a_users_balance(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create();

        $response = $this->authedAs($admin)->postJson("/api/admin/users/{$target->id}/adjust-balance", [
            'direction' => 'credit',
            'amount' => 250.00,
            'note' => 'Promotional bonus',
        ]);

        $response->assertOk()
            ->assertJsonPath('transaction.type', 'admin_credit')
            ->assertJsonPath('transaction.amount', '250.00');
        $this->assertEqualsWithDelta(250.0, (float) $response->json('balance'), 0.001);

        $this->assertEqualsWithDelta(250.00, app(LedgerService::class)->balanceFor($target), 0.001);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $target->id,
            'type' => 'admin_credit',
            'amount' => '250.00',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'user.balance.credit',
            'target_id' => $target->id,
        ]);
    }

    public function test_admin_can_debit_a_users_balance(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create();
        app(LedgerService::class)->credit($target, 'admin_credit', 500, 'seed');

        $response = $this->authedAs($admin)->postJson("/api/admin/users/{$target->id}/adjust-balance", [
            'direction' => 'debit',
            'amount' => 100.00,
            'note' => 'Manual correction',
        ]);

        $response->assertOk()
            ->assertJsonPath('transaction.type', 'admin_debit')
            ->assertJsonPath('transaction.amount', '-100.00');
        $this->assertEqualsWithDelta(400.0, (float) $response->json('balance'), 0.001);

        $this->assertEqualsWithDelta(400.00, app(LedgerService::class)->balanceFor($target), 0.001);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'user.balance.debit',
            'target_id' => $target->id,
        ]);
    }

    public function test_adjust_balance_requires_a_note(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create();

        $this->authedAs($admin)->postJson("/api/admin/users/{$target->id}/adjust-balance", [
            'direction' => 'credit',
            'amount' => 50,
        ])->assertStatus(422)->assertJsonValidationErrors(['note']);
    }

    public function test_adjust_balance_validates_direction_and_amount(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create();

        $this->authedAs($admin)->postJson("/api/admin/users/{$target->id}/adjust-balance", [
            'direction' => 'fly',
            'amount' => 10,
            'note' => 'x',
        ])->assertStatus(422)->assertJsonValidationErrors(['direction']);

        $this->authedAs($admin)->postJson("/api/admin/users/{$target->id}/adjust-balance", [
            'direction' => 'credit',
            'amount' => 0,
            'note' => 'x',
        ])->assertStatus(422)->assertJsonValidationErrors(['amount']);
    }

    public function test_regular_user_cannot_access_admin_user_endpoints(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        $this->authedAs($user)->getJson('/api/admin/users')->assertForbidden();
        $this->authedAs($user)->patchJson("/api/admin/users/{$target->id}", ['status' => 'suspended'])
            ->assertForbidden();
        $this->authedAs($user)->postJson("/api/admin/users/{$target->id}/adjust-balance", [
            'direction' => 'credit',
            'amount' => 10,
            'note' => 'x',
        ])->assertForbidden();
    }
}
