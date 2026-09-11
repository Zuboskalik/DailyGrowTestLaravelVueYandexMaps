<?php

namespace App\Services\Parsing;

class RealSleeper implements Sleeper
{
    public function sleep(float $seconds): void
    {
        usleep((int) ($seconds * 1_000_000));
    }
}
