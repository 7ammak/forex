<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_to_admin_route_returns_401(): void
    {
        $this->getJson('/api/admin/ping')->assertStatus(401);
    }

    public function test_regular_user_cannot_access_admin_route(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/admin/ping');

        $response->assertForbidden()
            ->assertJsonPath('message', 'This action requires administrator privileges.');
    }

    public function test_admin_user_can_access_admin_route(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/admin/ping');

        $response->assertOk()->assertJsonPath('message', 'pong');
    }

    public function test_suspended_admin_cannot_access_admin_route(): void
    {
        $admin = User::factory()->admin()->suspended()->create();
        $token = $admin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/admin/ping');

        $response->assertForbidden()
            ->assertJsonPath('message', 'Your account has been suspended.');
    }
}
