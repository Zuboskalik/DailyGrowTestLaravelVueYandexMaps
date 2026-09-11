<?php

namespace App\DTO;

final class OrganizationSnapshot
{
    public function __construct(
        public readonly string $yandexId,
        public readonly string $normalizedUrl,
        public readonly ?string $name,
        public readonly ?float $rating,
        public readonly ?int $ratingsCount,
    ) {
    }
}
