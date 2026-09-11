<?php

namespace App\DTO;

use Carbon\Carbon;

final class ReviewSnapshot
{
    public function __construct(
        public readonly string $externalId,
        public readonly ?string $authorName,
        public readonly ?int $rating,
        public readonly ?string $text,
        public readonly ?Carbon $createdAt,
    ) {
    }
}
