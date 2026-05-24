<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionApiTest extends TestCase
{
    use RefreshDatabase;

    private function authedFor(User $user): static
    {
        $token = $user->createToken('test')->plainTextToken;

        return $this->withHeader('Authorization', "Bearer $token");
    }

    public function test_index_returns_only_the_users_transactions_newest_first(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $ledger = app(LedgerService::class);
        $ledger->credit($alice, 'admin_credit', 100, 'first');
        $ledger->debit($alice, 'admin_debit', 25, 'second');
        $ledger->credit($bob, 'admin_credit', 999, 'bob');

        $response = $this->authedFor($alice)->getJson('/api/transactions');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(2, $data);
        foreach ($data as $row) {
            $this->assertSame($alice->id, $row['user_id']);
        }
        // Newest first
        $this->assertSame('admin_debit', $data[0]['type']);
        $this->assertSame('admin_credit', $data[1]['type']);
    }

    public function test_index_can_filter_by_type(): void
    {
        $user = User::factory()->create();
        $ledger = app(LedgerService::class);
        $ledger->credit($user, 'admin_credit', 100, null);
        $ledger->debit($user, 'trade_stake', 30, null);
        $ledger->credit($user, 'deposit_approved', 200, null);

        $response = $this->authedFor($user)->getJson('/api/transactions?type=trade_stake');
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('trade_stake', $response->json('data.0.type'));
    }

    public function test_index_validates_type_filter(): void
    {
        $user = User::factory()->create();
        $this->authedFor($user)->getJson('/api/transactions?type=bogus')
            ->assertStatus(422)->assertJsonValidationErrors(['type']);
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/transactions')->assertStatus(401);
    }
}
