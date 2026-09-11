<?php

namespace App\Jobs;

use App\Exceptions\Parsing\ParsingException;
use App\Models\Company;
use App\Models\ParsingLog;
use App\Models\Review;
use App\Services\YandexMapParserService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ParseYandexCompanyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $companyId,
        public readonly int $parsingLogId,
    ) {
        $this->onQueue('parsing');
    }

    /**
     * Creates the pending parsing_logs record and dispatches the job for it
     * — the entry point controllers/tests use instead of `new self(...)`.
     */
    public static function dispatchFor(Company $company): ParsingLog
    {
        $log = ParsingLog::create([
            'company_id' => $company->id,
            'status' => 'pending',
        ]);

        static::dispatch($company->id, $log->id);

        return $log;
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function middleware(): array
    {
        return [new WithoutOverlapping((string) $this->companyId)];
    }

    public function handle(YandexMapParserService $parser): void
    {
        $company = Company::findOrFail($this->companyId);
        $log = ParsingLog::findOrFail($this->parsingLogId);

        $log->update(['status' => 'processing', 'started_at' => now()]);
        $company->update(['parse_status' => 'processing']);

        $collected = 0;

        try {
            $organization = $parser->resolveOrganization($company->url);

            $company->update([
                'yandex_id' => $organization->yandexId,
                'normalized_url' => $organization->normalizedUrl,
                'name' => $organization->name,
                'rating' => $organization->rating,
                'ratings_count' => $organization->ratingsCount,
            ]);

            foreach (
                $parser->fetchReviews(
                    $organization->yandexId,
                    config('yandex_maps.max_reviews', 600),
                    $organization->ratingsCount,
                ) as $review
            ) {
                Review::updateOrCreate(
                    [
                        'company_id' => $company->id,
                        'external_id' => $review->externalId,
                    ],
                    [
                        'author_name' => $review->authorName,
                        'rating' => $review->rating,
                        'text' => $review->text,
                        'review_created_at' => $review->createdAt,
                    ],
                );

                $collected++;
            }

            $company->update([
                'parse_status' => 'completed',
                'last_parsed_at' => now(),
                'last_error' => null,
                'reviews_count' => $company->reviews()->count(),
            ]);

            $log->update([
                'status' => 'completed',
                'finished_at' => now(),
                'reviews_collected' => $collected,
            ]);
        } catch (ParsingException $e) {
            $company->update([
                'parse_status' => 'failed',
                'last_error' => $e->getMessage(),
            ]);

            $log->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_type' => $e->errorType(),
                'error_message' => $e->getMessage(),
                'reviews_collected' => $collected,
            ]);

            if (! $e->isRetryable()) {
                $this->fail($e);

                return;
            }

            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        ParsingLog::where('id', $this->parsingLogId)
            ->whereNotIn('status', ['completed', 'failed'])
            ->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_type' => $e instanceof ParsingException ? $e->errorType() : 'unknown',
                'error_message' => $e->getMessage(),
            ]);

        Company::where('id', $this->companyId)->update([
            'parse_status' => 'failed',
            'last_error' => $e->getMessage(),
        ]);
    }
}
