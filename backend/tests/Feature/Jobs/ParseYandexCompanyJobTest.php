<?php

namespace Tests\Feature\Jobs;

use App\Exceptions\Parsing\SourceBannedException;
use App\Jobs\ParseYandexCompanyJob;
use App\Models\Company;
use App\Services\Parsing\NullSleeper;
use App\Services\Parsing\Sleeper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ParseYandexCompanyJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(Sleeper::class, NullSleeper::class);
        config(['queue.default' => 'sync']);
    }

    private function fakeSuccessfulSource(string $yandexId, array $reviews, int $ratingCount = 2): void
    {
        Http::fake([
            "https://yandex.ru/maps/api/business/{$yandexId}/card" => Http::response([
                'id' => $yandexId,
                'name' => 'Test Company',
                'ratingValue' => 4.5,
                'ratingCount' => $ratingCount,
            ]),
            "https://yandex.ru/maps/api/business/{$yandexId}/reviews*" => Http::response([
                'reviews' => $reviews,
                'hasNextPage' => false,
            ]),
        ]);
    }

    public function test_successful_run_updates_company_and_stores_reviews(): void
    {
        $company = Company::factory()->create([
            'url' => 'https://yandex.ru/maps/org/test-company/555/',
            'parse_status' => 'idle',
        ]);

        $this->fakeSuccessfulSource('555', [
            ['id' => 'r1', 'author' => 'Alice', 'rating' => 5, 'text' => 'Great', 'date' => '2024-01-01T00:00:00+03:00'],
            ['id' => 'r2', 'author' => 'Bob', 'rating' => 4, 'text' => null, 'date' => '2024-01-02T00:00:00+03:00'],
        ]);

        $log = ParseYandexCompanyJob::dispatchFor($company);

        $company->refresh();
        $log->refresh();

        $this->assertSame('completed', $company->parse_status);
        $this->assertSame('Test Company', $company->name);
        $this->assertSame('555', $company->yandex_id);
        $this->assertEquals(4.5, $company->rating);
        $this->assertSame(2, $company->ratings_count);
        $this->assertSame(2, $company->reviews_count);
        $this->assertNotNull($company->last_parsed_at);
        $this->assertNull($company->last_error);

        $this->assertSame('completed', $log->status);
        $this->assertSame(2, $log->reviews_collected);
        $this->assertNotNull($log->started_at);
        $this->assertNotNull($log->finished_at);

        $this->assertDatabaseCount('reviews', 2);
        $this->assertDatabaseHas('reviews', [
            'company_id' => $company->id,
            'external_id' => 'r1',
            'author_name' => 'Alice',
            'rating' => 5,
        ]);
    }

    public function test_rerunning_the_job_upserts_reviews_without_creating_duplicates(): void
    {
        $company = Company::factory()->create([
            'url' => 'https://yandex.ru/maps/org/test-company/555/',
        ]);

        // Http::fake() accumulates stubs rather than replacing them, so both
        // runs must be declared upfront as a sequence (the reviews endpoint
        // is called once per dispatch: "Great" on the first run, "Updated
        // review" on the second) instead of re-faking between dispatches.
        Http::fake([
            'https://yandex.ru/maps/api/business/555/card' => Http::response([
                'id' => '555',
                'name' => 'Test Company',
                'ratingValue' => 4.5,
                'ratingCount' => 2,
            ]),
            'https://yandex.ru/maps/api/business/555/reviews*' => Http::sequence()
                ->push([
                    'reviews' => [
                        ['id' => 'r1', 'author' => 'Alice', 'rating' => 5, 'text' => 'Great', 'date' => '2024-01-01T00:00:00+03:00'],
                        ['id' => 'r2', 'author' => 'Bob', 'rating' => 4, 'text' => 'Nice', 'date' => '2024-01-02T00:00:00+03:00'],
                    ],
                    'hasNextPage' => false,
                ])
                ->push([
                    'reviews' => [
                        ['id' => 'r1', 'author' => 'Alice', 'rating' => 5, 'text' => 'Updated review', 'date' => '2024-01-01T00:00:00+03:00'],
                        ['id' => 'r2', 'author' => 'Bob', 'rating' => 4, 'text' => 'Nice', 'date' => '2024-01-02T00:00:00+03:00'],
                    ],
                    'hasNextPage' => false,
                ]),
        ]);

        ParseYandexCompanyJob::dispatchFor($company);
        $this->assertDatabaseCount('reviews', 2);
        $this->assertDatabaseCount('parsing_logs', 1);

        ParseYandexCompanyJob::dispatchFor($company);

        $this->assertDatabaseCount('reviews', 2);
        $this->assertDatabaseCount('parsing_logs', 2);
        $this->assertDatabaseHas('reviews', [
            'company_id' => $company->id,
            'external_id' => 'r1',
            'text' => 'Updated review',
        ]);

        $company->refresh();
        $this->assertSame(2, $company->reviews_count);
    }

    public function test_structure_changed_exception_marks_company_and_log_as_failed(): void
    {
        $company = Company::factory()->create([
            'url' => 'https://yandex.ru/maps/org/test-company/555/',
        ]);

        Http::fake([
            'https://yandex.ru/maps/api/business/555/card' => Http::response([
                'id' => '555',
                'name' => 'Test Company',
                'ratingValue' => 4.5,
                'ratingCount' => 10,
            ]),
            'https://yandex.ru/maps/api/business/555/reviews*' => Http::response([
                'unexpected_shape' => true,
            ]),
        ]);

        $log = ParseYandexCompanyJob::dispatchFor($company);

        $company->refresh();
        $log->refresh();

        $this->assertSame('failed', $company->parse_status);
        $this->assertNotNull($company->last_error);

        $this->assertSame('failed', $log->status);
        $this->assertSame('structure_changed', $log->error_type);
    }

    public function test_banned_response_is_classified_and_still_recorded_as_failed(): void
    {
        $company = Company::factory()->create([
            'url' => 'https://yandex.ru/maps/org/test-company/555/',
        ]);

        Http::fake([
            'https://yandex.ru/maps/api/business/555/card' => Http::response('captcha', 403),
        ]);

        // "Banned" is retryable, so the queue re-throws it to the caller
        // after recording the failure — unlike the non-retryable structure
        // change case, which the job swallows via $this->fail().
        try {
            ParseYandexCompanyJob::dispatchFor($company);
        } catch (SourceBannedException) {
            // Expected: the exception still propagates after being recorded.
        }

        $company->refresh();
        $log = $company->parsingLogs()->latest('id')->first();

        $this->assertSame('failed', $company->parse_status);
        $this->assertSame('failed', $log->status);
        $this->assertSame('banned', $log->error_type);
    }

    public function test_organization_not_found_is_classified_as_not_found(): void
    {
        $company = Company::factory()->create([
            'url' => 'https://yandex.ru/maps/org/test-company/555/',
        ]);

        Http::fake([
            'https://yandex.ru/maps/api/business/555/card' => Http::response('', 404),
        ]);

        $log = ParseYandexCompanyJob::dispatchFor($company);

        $company->refresh();
        $log->refresh();

        $this->assertSame('failed', $company->parse_status);
        $this->assertSame('failed', $log->status);
        $this->assertSame('not_found', $log->error_type);
    }

    public function test_source_timeout_is_classified_and_left_retryable(): void
    {
        $company = Company::factory()->create([
            'url' => 'https://yandex.ru/maps/org/test-company/555/',
        ]);

        Http::fake([
            'https://yandex.ru/maps/api/business/555/card' => Http::response('', 504),
        ]);

        try {
            ParseYandexCompanyJob::dispatchFor($company);
        } catch (\App\Exceptions\Parsing\SourceTimeoutException) {
            // Expected: timeout is retryable, so the queue re-throws it.
        }

        $company->refresh();
        $log = $company->parsingLogs()->latest('id')->first();

        $this->assertSame('failed', $company->parse_status);
        $this->assertSame('failed', $log->status);
        $this->assertSame('timeout', $log->error_type);
    }

    public function test_overlapping_dispatch_for_the_same_company_never_calls_the_source(): void
    {
        $company = Company::factory()->create([
            'url' => 'https://yandex.ru/maps/org/test-company/555/',
            'parse_status' => 'idle',
        ]);

        $this->fakeSuccessfulSource('555', [
            ['id' => 'r1', 'author' => 'Alice', 'rating' => 5, 'text' => 'Great', 'date' => '2024-01-01T00:00:00+03:00'],
        ]);

        // Simulate a parsing run for this company already in flight by
        // holding the same lock ParseYandexCompanyJob::middleware() uses.
        $lock = Cache::lock('laravel-queue-overlap:'.ParseYandexCompanyJob::class.':'.$company->id, 0);
        $this->assertTrue($lock->get());

        try {
            ParseYandexCompanyJob::dispatchFor($company);
        } catch (\Throwable) {
            // On the sync queue driver, releasing a job that can't run has
            // no real destination to release to; what matters here is that
            // the source was never called while the lock was held.
        }

        Http::assertNothingSent();

        $lock->release();
    }

    public function test_zero_reviews_with_zero_ratings_count_completes_successfully(): void
    {
        $company = Company::factory()->create([
            'url' => 'https://yandex.ru/maps/org/test-company/555/',
        ]);

        $this->fakeSuccessfulSource('555', [], ratingCount: 0);

        $log = ParseYandexCompanyJob::dispatchFor($company);

        $company->refresh();
        $log->refresh();

        $this->assertSame('completed', $company->parse_status);
        $this->assertSame(0, $company->reviews_count);
        $this->assertSame('completed', $log->status);
        $this->assertDatabaseCount('reviews', 0);
    }
}
