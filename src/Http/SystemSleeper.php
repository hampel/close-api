<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Http;

/**
 * Sleeps for real.
 */
final class SystemSleeper implements Sleeper
{
    public function sleep(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        usleep((int) round($seconds * 1_000_000));
    }
}
