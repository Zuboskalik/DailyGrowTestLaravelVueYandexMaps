<?php

namespace App\Exceptions\Parsing;

class OrganizationNotFoundException extends ParsingException
{
    public function errorType(): string
    {
        return 'not_found';
    }

    public function isRetryable(): bool
    {
        return false;
    }
}
