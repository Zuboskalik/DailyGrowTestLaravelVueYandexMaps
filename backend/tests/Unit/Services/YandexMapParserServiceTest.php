<?php

namespace Tests\Unit\Services;

use App\Exceptions\Parsing\OrganizationNotFoundException;
use App\Exceptions\Parsing\ParsingStructureChangedException;
use App\Exceptions\Parsing\SourceBannedException;
use App\Exceptions\Parsing\SourceTimeoutException;
use App\Services\Parsing\HttpJsonStrategy;
use App\Services\Parsing\NullSleeper;
use App\Services\Parsing\Sleeper;
use App\Services\Parsing\UserAgentPool;
use App\Services\YandexMapParserService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class YandexMapParserServiceTest extends TestCase
{
    private function makeService(int $pageSize = 50): YandexMapParserService
    {
        $strategy = new HttpJsonStrategy(
            baseUrl: 'https://yandex.ru',
            timeout: 10,
            pageSize: $pageSize,
            userAgentPool: new UserAgentPool(['TestAgent/1.0']),
            sleeper: new NullSleeper(),
            throttleMinSeconds: 0,
            throttleMaxSeconds: 0,
        );

        return new YandexMapParserService($strategy);
    }

    private function reviewsPage(int $count, string $prefix = 'r'): array
    {
        $reviews = [];

        for ($i = 1; $i <= $count; $i++) {
            $reviews[] = [
                'id' => "{$prefix}{$i}",
                'author' => "Author {$i}",
                'rating' => 5,
                'text' => "Review text {$i}",
                'date' => '2024-01-01T10:00:00+03:00',
            ];
        }

        return $reviews;
    }

    public function test_resolves_organization_and_extracts_metrics(): void
    {
        Http::fake([
            'https://yandex.ru/maps/api/business/123456/card' => Http::response([
                'id' => '123456',
                'name' => 'Кафе Ромашка',
                'ratingValue' => 4.7,
                'ratingCount' => 123,
            ]),
        ]);

        $snapshot = $this->makeService()->resolveOrganization('https://yandex.ru/maps/org/kafe-romashka/123456/');

        $this->assertSame('123456', $snapshot->yandexId);
        $this->assertSame('Кафе Ромашка', $snapshot->name);
        $this->assertSame(4.7, $snapshot->rating);
        $this->assertSame(123, $snapshot->ratingsCount);
    }

    public function test_resolves_organization_id_from_oid_query_parameter(): void
    {
        Http::fake([
            'https://yandex.ru/maps/api/business/999/card' => Http::response([
                'name' => 'Test Org',
                'ratingValue' => 5,
                'ratingCount' => 1,
            ]),
        ]);

        $snapshot = $this->makeService()->resolveOrganization('https://yandex.ru/maps/213/moscow/?oid=999');

        $this->assertSame('999', $snapshot->yandexId);
    }

    public function test_missing_organization_throws_not_found(): void
    {
        Http::fake([
            'https://yandex.ru/maps/api/business/123456/card' => Http::response([], 404),
        ]);

        $this->expectException(OrganizationNotFoundException::class);

        $this->makeService()->resolveOrganization('https://yandex.ru/maps/org/kafe-romashka/123456/');
    }

    public function test_card_response_missing_name_key_throws_structure_changed(): void
    {
        Http::fake([
            'https://yandex.ru/maps/api/business/123456/card' => Http::response([
                'ratingValue' => 4.7,
            ]),
        ]);

        $this->expectException(ParsingStructureChangedException::class);

        $this->makeService()->resolveOrganization('https://yandex.ru/maps/org/kafe-romashka/123456/');
    }

    public function test_banned_response_throws_source_banned_exception(): void
    {
        Http::fake([
            'https://yandex.ru/maps/api/business/123456/card' => Http::response('captcha', 403),
        ]);

        $this->expectException(SourceBannedException::class);

        $this->makeService()->resolveOrganization('https://yandex.ru/maps/org/kafe-romashka/123456/');
    }

    public function test_fetches_reviews_across_multiple_pages_and_maps_fields(): void
    {
        Http::fake([
            'https://yandex.ru/maps/api/business/123456/reviews*' => Http::sequence()
                ->push(['reviews' => $this->reviewsPage(50, 'p1-'), 'hasNextPage' => true])
                ->push(['reviews' => $this->reviewsPage(20, 'p2-'), 'hasNextPage' => false]),
        ]);

        $reviews = iterator_to_array($this->makeService()->fetchReviews('123456', 600));

        $this->assertCount(70, $reviews);
        $this->assertSame('p1-1', $reviews[0]->externalId);
        $this->assertSame('Author 1', $reviews[0]->authorName);
        $this->assertSame(5, $reviews[0]->rating);
        $this->assertSame('Review text 1', $reviews[0]->text);
        $this->assertNotNull($reviews[0]->createdAt);
        $this->assertSame('p2-20', $reviews[69]->externalId);
    }

    public function test_stops_collecting_once_the_600_review_limit_is_reached(): void
    {
        Http::fake([
            'https://yandex.ru/maps/api/business/123456/reviews*' => Http::response([
                'reviews' => $this->reviewsPage(50),
                'hasNextPage' => true,
            ]),
        ]);

        $reviews = iterator_to_array($this->makeService()->fetchReviews('123456', 600));

        $this->assertCount(600, $reviews);
        Http::assertSentCount(12); // ceil(600 / 50) pages, no 13th request issued
    }

    public function test_reviews_response_missing_reviews_key_throws_structure_changed(): void
    {
        Http::fake([
            'https://yandex.ru/maps/api/business/123456/reviews*' => Http::response(['unexpected' => true]),
        ]);

        $this->expectException(ParsingStructureChangedException::class);

        iterator_to_array($this->makeService()->fetchReviews('123456', 600));
    }

    public function test_zero_reviews_with_positive_ratings_count_throws_structure_changed(): void
    {
        Http::fake([
            'https://yandex.ru/maps/api/business/123456/reviews*' => Http::response(['reviews' => [], 'hasNextPage' => false]),
        ]);

        $this->expectException(ParsingStructureChangedException::class);

        iterator_to_array($this->makeService()->fetchReviews('123456', 600, ratingsCount: 10));
    }

    public function test_zero_reviews_with_zero_ratings_count_is_not_an_error(): void
    {
        Http::fake([
            'https://yandex.ru/maps/api/business/123456/reviews*' => Http::response(['reviews' => [], 'hasNextPage' => false]),
        ]);

        $reviews = iterator_to_array($this->makeService()->fetchReviews('123456', 600, ratingsCount: 0));

        $this->assertCount(0, $reviews);
    }

    public function test_the_same_user_agent_is_reused_for_every_request_in_a_run(): void
    {
        Http::fake([
            'https://yandex.ru/maps/api/business/123456/reviews*' => Http::response([
                'reviews' => $this->reviewsPage(50),
                'hasNextPage' => true,
            ]),
        ]);

        $strategy = new HttpJsonStrategy(
            baseUrl: 'https://yandex.ru',
            timeout: 10,
            pageSize: 50,
            userAgentPool: new UserAgentPool(['Agent-A', 'Agent-B', 'Agent-C']),
            sleeper: new NullSleeper(),
            throttleMinSeconds: 0,
            throttleMaxSeconds: 0,
        );

        iterator_to_array((new YandexMapParserService($strategy))->fetchReviews('123456', 150));

        $usedAgents = Http::recorded()
            ->map(fn ($pair) => $pair[0]->header('User-Agent')[0] ?? null)
            ->unique();

        $this->assertCount(1, $usedAgents, 'Expected a single, consistent User-Agent across the whole run.');
    }

    public function test_it_throttles_between_review_pages_but_not_before_the_first_request(): void
    {
        Http::fake([
            'https://yandex.ru/maps/api/business/123456/reviews*' => Http::response([
                'reviews' => $this->reviewsPage(50),
                'hasNextPage' => true,
            ]),
        ]);

        $sleeper = new class implements Sleeper
        {
            public int $calls = 0;

            public function sleep(float $seconds): void
            {
                $this->calls++;
            }
        };

        $strategy = new HttpJsonStrategy(
            baseUrl: 'https://yandex.ru',
            timeout: 10,
            pageSize: 50,
            userAgentPool: new UserAgentPool(['TestAgent/1.0']),
            sleeper: $sleeper,
            throttleMinSeconds: 1,
            throttleMaxSeconds: 3,
        );

        iterator_to_array((new YandexMapParserService($strategy))->fetchReviews('123456', 150));

        // 3 pages fetched (150 / 50), throttled before the 2nd and 3rd only.
        $this->assertSame(2, $sleeper->calls);
    }

    public function test_connection_failure_is_classified_as_a_timeout(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Could not connect to host.');
        });

        $this->expectException(SourceTimeoutException::class);

        $this->makeService()->resolveOrganization('https://yandex.ru/maps/org/kafe-romashka/123456/');
    }
}
