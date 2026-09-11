<?php

namespace App\Exceptions\Parsing;

class SourceBannedException extends ParsingException
{
    public function errorType(): string
    {
        return 'banned';
    }
}
