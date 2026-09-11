<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_to_protected_api_route_is_rejected(): void
    {
        $response = $this->getJson('/api/companies');

        $response->assertStatus(401);
    }

    public function test_unauthenticated_request_to_user_route_is_rejected(): void
    {
        $response = $this->getJson('/api/user');

        $response->assertStatus(401);
    }

    public function test_authenticated_request_to_protected_api_route_is_allowed(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/companies');

        $response->assertOk();
    }
}
