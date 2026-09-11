<?php

namespace Tests\Feature\Companies;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreCompanyTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_new_company_from_a_valid_url(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/companies', [
            'url' => 'https://yandex.ru/maps/org/kafe-romashka/1234567890/',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.yandex_id', '1234567890');
        $response->assertJsonPath('data.parse_status', 'idle');
        $this->assertDatabaseCount('companies', 1);
    }

    public function test_resubmitting_the_same_url_returns_the_existing_company_without_duplicating(): void
    {
        $user = User::factory()->create();

        $first = $this->actingAs($user)->postJson('/api/companies', [
            'url' => 'https://yandex.ru/maps/org/kafe-romashka/1234567890/',
        ]);
        $first->assertCreated();

        $second = $this->actingAs($user)->postJson('/api/companies', [
            'url' => 'https://yandex.ru/maps/org/kafe-romashka/1234567890/?ll=1,1&z=16',
        ]);

        $second->assertOk();
        $second->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertDatabaseCount('companies', 1);
    }

    public function test_resubmitting_the_same_organization_via_oid_query_param_is_deduplicated(): void
    {
        $user = User::factory()->create();
        Company::factory()->create(['yandex_id' => '999', 'normalized_url' => 'https://yandex.ru/maps/org/x/999']);

        $response = $this->actingAs($user)->postJson('/api/companies', [
            'url' => 'https://yandex.ru/maps/213/moscow/?oid=999',
        ]);

        $response->assertOk();
        $this->assertDatabaseCount('companies', 1);
    }

    public function test_invalid_url_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/companies', [
            'url' => 'https://maps.google.com/maps/org/kafe-romashka/1234567890/',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('url');
        $this->assertDatabaseCount('companies', 0);
    }

    public function test_storing_a_company_does_not_dispatch_a_parsing_job(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/companies', [
            'url' => 'https://yandex.ru/maps/org/kafe-romashka/1234567890/',
        ])->assertCreated();

        $this->assertDatabaseCount('parsing_logs', 0);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->postJson('/api/companies', [
            'url' => 'https://yandex.ru/maps/org/kafe-romashka/1234567890/',
        ]);

        $response->assertStatus(401);
    }
}
