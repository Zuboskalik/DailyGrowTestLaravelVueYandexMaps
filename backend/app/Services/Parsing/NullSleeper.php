<?php

namespace App\Services\Parsing;

class NullSleeper implements Sleeper
{
    public function sleep(float $seconds): void
    {
        // No-op, used in tests to avoid slowing down the suite.
    }
}
