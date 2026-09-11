<?php

namespace Tests\Feature\Companies;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowCompanyTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_companies(): void
    {
        $user = User::factory()->create();
        Company::factory()->count(3)->create();

        $response = $this->actingAs($user)->getJson('/api/companies');

        $response->assertOk();
        $this->assertCount(3, $response->json('data'));
    }

    public function test_show_returns_metrics_and_status(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create([
            'rating' => 4.5,
            'ratings_count' => 42,
            'reviews_count' => 10,
            'parse_status' => 'completed',
        ]);

        $response = $this->actingAs($user)->getJson("/api/companies/{$company->id}");

        $response->assertOk();
        $response->assertJsonPath('data.rating', 4.5);
        $response->assertJsonPath('data.ratings_count', 42);
        $response->assertJsonPath('data.reviews_count', 10);
        $response->assertJsonPath('data.parse_status', 'completed');
        $response->assertJsonStructure(['data' => ['last_parsed_at']]);
    }

    public function test_show_returns_404_for_unknown_company(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/companies/999999');

        $response->assertStatus(404);
    }
}
