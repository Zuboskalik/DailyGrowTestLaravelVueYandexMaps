<?php

namespace App\Exceptions\Parsing;

use Exception;

abstract class ParsingException extends Exception
{
    abstract public function errorType(): string;

    /** Whether ParseYandexCompanyJob should let the queue retry this failure. */
    public function isRetryable(): bool
    {
        return true;
    }
}
