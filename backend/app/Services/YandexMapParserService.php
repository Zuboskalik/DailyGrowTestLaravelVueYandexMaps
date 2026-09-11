<?php

namespace App\Services;

use App\DTO\OrganizationSnapshot;
use App\DTO\ReviewSnapshot;
use App\Exceptions\Parsing\ParsingStructureChangedException;
use App\Services\Parsing\ReviewSourceStrategy;
use Generator;

/**
 * Pure orchestration over a ReviewSourceStrategy — knows nothing about
 * queues, controllers, or Eloquent. See plan.md §1.3.
 */
class YandexMapParserService
{
    public function __construct(private readonly ReviewSourceStrategy $strategy)
    {
    }

    public function resolveOrganization(string $url): OrganizationSnapshot
    {
        return $this->strategy->resolveOrganization($url);
    }

    /**
     * @return Generator<int, ReviewSnapshot>
     */
    public function fetchReviews(string $yandexId, int $limit = 600, ?int $ratingsCount = null): Generator
    {
        $collected = 0;

        foreach ($this->strategy->fetchReviews($yandexId, $limit) as $review) {
            $collected++;

            yield $review;
        }

        if ($collected === 0 && $ratingsCount !== null && $ratingsCount > 0) {
            throw new ParsingStructureChangedException(
                "Source reports {$ratingsCount} ratings but the reviews endpoint returned none."
            );
        }
    }
}
