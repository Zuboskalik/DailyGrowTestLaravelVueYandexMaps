<?php

namespace Tests\Feature\Companies;

use App\Jobs\ParseYandexCompanyJob;
use App\Models\Company;
use App\Models\ParsingLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ParseCompanyTest extends TestCase
{
    use RefreshDatabase;

    public function test_starting_a_parse_run_dispatches_the_job(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $company = Company::factory()->create(['parse_status' => 'idle']);

        $response = $this->actingAs($user)->postJson("/api/companies/{$company->id}/parse");

        $response->assertStatus(202);
        $this->assertDatabaseHas('parsing_logs', [
            'company_id' => $company->id,
            'status' => 'pending',
        ]);
        Queue::assertPushed(ParseYandexCompanyJob::class);
    }

    public function test_starting_a_parse_run_immediately_marks_the_company_as_pending(): void
    {
        // Without this, a company polling its own status would show
        // "idle" until a queue worker actually picks the job up.
        Queue::fake();
        $user = User::factory()->create();
        $company = Company::factory()->create(['parse_status' => 'idle']);

        $this->actingAs($user)->postJson("/api/companies/{$company->id}/parse")
            ->assertStatus(202);

        $this->assertSame('pending', $company->refresh()->parse_status);
    }

    public function test_starting_a_parse_run_while_one_is_already_active_returns_conflict(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $company = Company::factory()->create(['parse_status' => 'processing']);
        ParsingLog::factory()->create(['company_id' => $company->id, 'status' => 'processing']);

        $response = $this->actingAs($user)->postJson("/api/companies/{$company->id}/parse");

        $response->assertStatus(409);
        $this->assertDatabaseCount('parsing_logs', 1);
        Queue::assertNotPushed(ParseYandexCompanyJob::class);
    }

    public function test_starting_a_parse_run_after_the_previous_one_completed_is_allowed(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $company = Company::factory()->create(['parse_status' => 'completed']);
        ParsingLog::factory()->create(['company_id' => $company->id, 'status' => 'completed']);

        $response = $this->actingAs($user)->postJson("/api/companies/{$company->id}/parse");

        $response->assertStatus(202);
        $this->assertDatabaseCount('parsing_logs', 2);
        Queue::assertPushed(ParseYandexCompanyJob::class);
    }
}
