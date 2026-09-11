<?php

namespace App\Exceptions\Parsing;

class SourceTimeoutException extends ParsingException
{
    public function errorType(): string
    {
        return 'timeout';
    }
}
