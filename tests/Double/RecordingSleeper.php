<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Tests\Double;

use Hampel\CloseApi\Http\Sleeper;

/**
 * Records what it was asked to wait for, and waits for none of it.
 *
 * @phpstan-type Slept list<float>
 */
final class RecordingSleeper implements Sleeper
{
    /** @var list<float> */
    public array $slept = [];

    public function sleep(float $seconds): void
    {
        $this->slept[] = $seconds;
    }

    public function total(): float
    {
        return array_sum($this->slept);
    }
}
