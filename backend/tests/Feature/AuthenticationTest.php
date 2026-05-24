<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_and_receives_a_token(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'password123',
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'user' => ['id', 'name', 'email'],
                'token',
            ])
            ->assertJsonPath('user.email', 'alice@example.com');

        $this->assertDatabaseHas('users', [
            'email' => 'alice@example.com',
            'role' => 'user',
            'status' => 'active',
        ]);

        $user = User::where('email', 'alice@example.com')->first();
        $this->assertTrue(Hash::check('password123', $user->password));
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_register_validates_required_fields(): void
    {
        $response = $this->postJson('/api/register', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_register_rejects_invalid_email(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Alice',
            'email' => 'not-an-email',
            'password' => 'password123',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_register_rejects_short_password(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'short',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    public function test_register_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->postJson('/api/register', [
            'name' => 'Bob',
            'email' => 'taken@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_user_can_login_with_correct_credentials(): void
    {
        User::factory()->create([
            'email' => 'bob@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'bob@example.com',
            'password' => 'secret123',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['user' => ['id', 'name', 'email'], 'token'])
            ->assertJsonPath('user.email', 'bob@example.com');
    }

    public function test_login_rejects_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'bob@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'bob@example.com',
            'password' => 'wrong',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_login_rejects_unknown_email(): void
    {
        $response = $this->postJson('/api/login', [
            'email' => 'nobody@example.com',
            'password' => 'whatever',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_login_validates_required_fields(): void
    {
        $response = $this->postJson('/api/login', []);

        $response->assertStatus(422)->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_suspended_user_cannot_log_in(): void
    {
        User::factory()->suspended()->create([
            'email' => 'banned@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'banned@example.com',
            'password' => 'secret123',
        ]);

        $response->assertForbidden()
            ->assertJsonPath('message', 'Your account has been suspended.');
    }

    public function test_authenticated_user_can_call_me_endpoint(): void
    {
        $user = User::factory()->create();
        app(LedgerService::class)->credit($user, 'admin_credit', 250.00, 'opening');
        app(LedgerService::class)->debit($user, 'trade_stake', 100.00, 'stake');

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/me');

        $response->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.email', $user->email);

        $this->assertEqualsWithDelta(150.0, (float) $response->json('balance'), 0.001);
        $this->assertEqualsWithDelta(150.0, (float) $response->json('available_balance'), 0.001);
    }

    public function test_me_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/me')->assertStatus(401);
    }

    public function test_user_can_log_out_and_token_is_removed_from_database(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $logout = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/logout');

        $logout->assertOk()->assertJsonPath('message', 'Logged out');
        $this->assertSame(0, $user->fresh()->tokens()->count());
    }

    public function test_revoked_token_can_no_longer_authenticate(): void
    {
        $user = User::factory()->create();
        $issued = $user->createToken('test');
        $plain = $issued->plainTextToken;

        // Simulate logout having happened in some prior request — just delete the row.
        $issued->accessToken->delete();

        // Bust the in-process auth-manager cache so the next call resolves auth fresh.
        Auth::forgetGuards();

        $this->withHeader('Authorization', "Bearer $plain")
            ->getJson('/api/me')
            ->assertStatus(401);
    }

    public function test_suspended_user_with_existing_token_is_blocked_and_tokens_revoked(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        // Suspend after token issued.
        $user->update(['status' => 'suspended']);
        Auth::forgetGuards();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/me');

        $response->assertForbidden()
            ->assertJsonPath('message', 'Your account has been suspended.');

        $this->assertSame(0, $user->fresh()->tokens()->count());
    }
}
