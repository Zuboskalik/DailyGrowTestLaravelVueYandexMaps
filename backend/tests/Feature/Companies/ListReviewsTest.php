<?php

namespace Tests\Feature\Companies;

use App\Models\Company;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListReviewsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reviews_are_paginated_by_50(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create();
        Review::factory()->count(120)->create(['company_id' => $company->id]);

        $page1 = $this->actingAs($user)->getJson("/api/companies/{$company->id}/reviews?page=1");
        $page1->assertOk();
        $this->assertCount(50, $page1->json('data'));
        $this->assertSame(1, $page1->json('meta.current_page'));
        $this->assertSame(3, $page1->json('meta.last_page'));
        $this->assertSame(120, $page1->json('meta.total'));

        $page3 = $this->actingAs($user)->getJson("/api/companies/{$company->id}/reviews?page=3");
        $page3->assertOk();
        $this->assertCount(20, $page3->json('data'));
    }

    public function test_reviews_are_sorted_from_newest_to_oldest(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create();

        $older = Review::factory()->create([
            'company_id' => $company->id,
            'review_created_at' => now()->subDays(5),
        ]);
        $newer = Review::factory()->create([
            'company_id' => $company->id,
            'review_created_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($user)->getJson("/api/companies/{$company->id}/reviews");

        $response->assertOk();
        $this->assertSame($newer->id, $response->json('data.0.id'));
        $this->assertSame($older->id, $response->json('data.1.id'));
    }

    public function test_empty_review_list_returns_200_with_no_data(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create();

        $response = $this->actingAs($user)->getJson("/api/companies/{$company->id}/reviews");

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_review_without_text_is_flagged_via_has_text(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create();
        Review::factory()->create(['company_id' => $company->id, 'text' => null]);

        $response = $this->actingAs($user)->getJson("/api/companies/{$company->id}/reviews");

        $response->assertOk();
        $this->assertFalse($response->json('data.0.has_text'));
    }
}
