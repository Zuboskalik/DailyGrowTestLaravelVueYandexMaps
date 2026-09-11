<?php

namespace App\Exceptions\Parsing;

class ParsingStructureChangedException extends ParsingException
{
    public function errorType(): string
    {
        return 'structure_changed';
    }

    public function isRetryable(): bool
    {
        // Retrying the same job cannot fix a broken response contract.
        return false;
    }
}
