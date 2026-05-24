<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAuditLogTest extends TestCase
{
    use RefreshDatabase;

    private function authedAs(User $user): static
    {
        $token = $user->createToken('test')->plainTextToken;

        return $this->withHeader('Authorization', "Bearer $token");
    }

    public function test_returns_recent_logs_newest_first_with_actor(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->admin()->create(['name' => 'Other Admin']);

        AuditLog::factory()->create([
            'actor_id' => $admin->id,
            'action' => 'user.suspended',
            'created_at' => now()->subHours(2),
        ]);
        AuditLog::factory()->create([
            'actor_id' => $other->id,
            'action' => 'deposit.approved',
            'created_at' => now()->subHour(),
        ]);
        AuditLog::factory()->create([
            'actor_id' => $admin->id,
            'action' => 'trade.resolved',
            'created_at' => now(),
        ]);

        $response = $this->authedAs($admin)->getJson('/api/admin/audit-logs?limit=5');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(3, $data);
        $actions = array_column($data, 'action');
        $this->assertSame(['trade.resolved', 'deposit.approved', 'user.suspended'], $actions);
        $this->assertSame($other->name, $data[1]['actor']['name']);
    }

    public function test_respects_limit_query_param(): void
    {
        $admin = User::factory()->admin()->create();
        AuditLog::factory()->count(8)->create(['actor_id' => $admin->id]);

        $response = $this->authedAs($admin)->getJson('/api/admin/audit-logs?limit=3');
        $response->assertOk();
        $this->assertCount(3, $response->json('data'));
    }

    public function test_validates_limit(): void
    {
        $admin = User::factory()->admin()->create();
        $this->authedAs($admin)->getJson('/api/admin/audit-logs?limit=200')
            ->assertStatus(422)->assertJsonValidationErrors(['limit']);
    }

    public function test_forbids_non_admin(): void
    {
        $user = User::factory()->create();
        $this->authedAs($user)->getJson('/api/admin/audit-logs')->assertForbidden();
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/admin/audit-logs')->assertStatus(401);
    }
}
