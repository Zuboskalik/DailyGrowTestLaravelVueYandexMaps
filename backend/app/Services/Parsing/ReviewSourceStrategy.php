<?php

namespace App\Services\Parsing;

use App\DTO\OrganizationSnapshot;
use App\DTO\ReviewSnapshot;
use Generator;

interface ReviewSourceStrategy
{
    public function resolveOrganization(string $url): OrganizationSnapshot;

    /**
     * @return Generator<int, ReviewSnapshot>
     */
    public function fetchReviews(string $yandexId, int $limit): Generator;
}
