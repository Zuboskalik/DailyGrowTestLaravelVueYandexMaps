<?php

namespace App\Services\Parsing;

interface Sleeper
{
    public function sleep(float $seconds): void;
}
