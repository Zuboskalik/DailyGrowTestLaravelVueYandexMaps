<?php

namespace App\Services\Parsing;

use App\DTO\OrganizationSnapshot;
use App\DTO\ReviewSnapshot;
use App\Exceptions\Parsing\OrganizationNotFoundException;
use App\Exceptions\Parsing\ParsingStructureChangedException;
use App\Exceptions\Parsing\SourceBannedException;
use App\Exceptions\Parsing\SourceTimeoutException;
use App\Support\YandexOrganizationUrl;
use Carbon\Carbon;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Talks to Yandex Maps' internal JSON endpoints that the organization page
 * itself calls to render the review widget, instead of rendering the page
 * in a headless browser (see plan.md §1.2 for the rationale).
 */
class HttpJsonStrategy implements ReviewSourceStrategy
{
    private readonly string $userAgent;

    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeout,
        private readonly int $pageSize,
        UserAgentPool $userAgentPool,
        private readonly Sleeper $sleeper,
        private readonly float $throttleMinSeconds = 1.0,
        private readonly float $throttleMaxSeconds = 3.0,
    ) {
        $this->userAgent = $userAgentPool->pickOne();
    }

    public function resolveOrganization(string $url): OrganizationSnapshot
    {
        $canonicalUrl = $this->resolveRedirect($url);
        $yandexId = $this->extractYandexId($canonicalUrl);

        if ($yandexId === null) {
            throw new OrganizationNotFoundException("Could not extract an organization id from {$canonicalUrl}");
        }

        $response = $this->get("{$this->baseUrl}/maps/api/business/{$yandexId}/card");

        $this->guardAgainstFailure($response);

        $data = $response->json();

        if (! is_array($data) || ! array_key_exists('name', $data)) {
            throw new ParsingStructureChangedException(
                'Organization card response is missing the expected "name" key.'
            );
        }

        return new OrganizationSnapshot(
            yandexId: $yandexId,
            normalizedUrl: $canonicalUrl,
            name: $data['name'] ?? null,
            rating: isset($data['ratingValue']) ? (float) $data['ratingValue'] : null,
            ratingsCount: isset($data['ratingCount']) ? (int) $data['ratingCount'] : null,
        );
    }

    public function fetchReviews(string $yandexId, int $limit): Generator
    {
        $page = 1;
        $collected = 0;

        while ($collected < $limit) {
            if ($page > 1) {
                $this->sleeper->sleep(mt_rand(
                    (int) ($this->throttleMinSeconds * 1000),
                    (int) ($this->throttleMaxSeconds * 1000),
                ) / 1000);
            }

            $response = $this->get("{$this->baseUrl}/maps/api/business/{$yandexId}/reviews", [
                'page' => $page,
                'pageSize' => $this->pageSize,
            ]);

            $this->guardAgainstFailure($response);

            $data = $response->json();

            if (! is_array($data) || ! array_key_exists('reviews', $data) || ! is_array($data['reviews'])) {
                throw new ParsingStructureChangedException(
                    'Reviews response is missing the expected "reviews" array.'
                );
            }

            if (count($data['reviews']) === 0) {
                return;
            }

            foreach ($data['reviews'] as $review) {
                yield $this->toReviewSnapshot($review);

                $collected++;

                if ($collected >= $limit) {
                    return;
                }
            }

            $hasNextPage = $data['hasNextPage'] ?? (count($data['reviews']) >= $this->pageSize);

            if (! $hasNextPage) {
                return;
            }

            $page++;
        }
    }

    private function toReviewSnapshot(mixed $review): ReviewSnapshot
    {
        if (! is_array($review) || ! array_key_exists('id', $review)) {
            throw new ParsingStructureChangedException(
                'A review entry is missing the expected "id" key.'
            );
        }

        return new ReviewSnapshot(
            externalId: (string) $review['id'],
            authorName: $review['author'] ?? null,
            rating: isset($review['rating']) ? (int) $review['rating'] : null,
            text: $review['text'] ?? null,
            createdAt: isset($review['date']) ? Carbon::parse($review['date']) : null,
        );
    }

    private function request(): PendingRequest
    {
        return Http::withUserAgent($this->userAgent)
            ->timeout($this->timeout)
            ->acceptJson();
    }

    private function get(string $url, array $query = []): Response
    {
        try {
            return $this->request()->get($url, $query);
        } catch (ConnectionException $e) {
            throw new SourceTimeoutException($e->getMessage(), previous: $e);
        }
    }

    private function guardAgainstFailure(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();

        if ($status === 404) {
            throw new OrganizationNotFoundException("Yandex Maps returned 404 for the requested resource.");
        }

        if ($status === 403 || $status === 429) {
            throw new SourceBannedException("Yandex Maps blocked the request (HTTP {$status}).");
        }

        if ($status === 408 || $status >= 500) {
            throw new SourceTimeoutException("Yandex Maps did not respond in time (HTTP {$status}).");
        }

        throw new SourceBannedException("Unexpected response from Yandex Maps (HTTP {$status}).");
    }

    /**
     * Follows redirects for short links (yandex.ru/maps/-/...) and returns
     * the final canonical URL.
     */
    private function resolveRedirect(string $url): string
    {
        if (! str_contains($url, '/maps/-/')) {
            return $url;
        }

        try {
            $response = $this->request()
                ->withOptions(['allow_redirects' => ['track_redirects' => true]])
                ->get($url);
        } catch (ConnectionException $e) {
            throw new SourceTimeoutException($e->getMessage(), previous: $e);
        }

        $this->guardAgainstFailure($response);

        $history = $response->handlerStats()['redirect_url'] ?? null;

        return $history ?: $url;
    }

    private function extractYandexId(string $url): ?string
    {
        return YandexOrganizationUrl::extractId($url);
    }
}
