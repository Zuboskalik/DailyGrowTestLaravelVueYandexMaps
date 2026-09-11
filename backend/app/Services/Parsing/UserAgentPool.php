<?php

namespace App\Services\Parsing;

class UserAgentPool
{
    public function __construct(private readonly array $pool)
    {
    }

    /** One UA per job run — kept consistent for the whole strategy instance. */
    public function pickOne(): string
    {
        return $this->pool[array_rand($this->pool)];
    }
}
