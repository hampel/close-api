<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Http;

/**
 * Waits.
 *
 * An interface for one call to usleep() looks like ceremony until you try to
 * test a retry policy without it: the alternative is a suite whose runtime is
 * the sum of the backoff intervals it is checking, which is a suite people stop
 * running.
 */
interface Sleeper
{
    public function sleep(float $seconds): void;
}
