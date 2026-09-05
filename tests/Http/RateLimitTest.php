<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Tests\Http;

use Hampel\CloseApi\Http\RateLimit;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RateLimitTest extends TestCase
{
    #[Test]
    public function it_parses_the_documented_header(): void
    {
        $limit = RateLimit::fromHeader('limit=100, remaining=50, reset=5');

        $this->assertNotNull($limit);
        $this->assertSame(100, $limit->limit);
        $this->assertSame(50, $limit->remaining);
        $this->assertSame(5.0, $limit->reset);
    }

    /**
     * Close documents reset as "seconds remaining ... (as a decimal)", so
     * reading it as an integer loses most of a second on every wait.
     */
    #[Test]
    public function it_keeps_the_fractional_part_of_reset(): void
    {
        $limit = RateLimit::fromHeader('limit=100, remaining=0, reset=0.85');

        $this->assertNotNull($limit);
        $this->assertSame(0.85, $limit->reset);
    }

    #[Test]
    public function it_tolerates_whitespace_and_quoting(): void
    {
        $limit = RateLimit::fromHeader('limit="20",remaining="19",  reset="1.5"');

        $this->assertNotNull($limit);
        $this->assertSame(20, $limit->limit);
        $this->assertSame(19, $limit->remaining);
        $this->assertSame(1.5, $limit->reset);
    }

    #[Test]
    public function it_ignores_the_order_of_the_values(): void
    {
        $limit = RateLimit::fromHeader('reset=2, limit=10, remaining=3');

        $this->assertNotNull($limit);
        $this->assertSame(10, $limit->limit);
    }

    /**
     * Informational metadata must not be able to fail a successful request.
     */
    #[Test]
    public function it_returns_null_for_anything_it_cannot_parse(): void
    {
        $this->assertNull(RateLimit::fromHeader(''));
        $this->assertNull(RateLimit::fromHeader('limit=100, remaining=50'));
        $this->assertNull(RateLimit::fromHeader('limit=lots, remaining=50, reset=5'));
        $this->assertNull(RateLimit::fromHeader('nonsense'));
    }

    #[Test]
    public function reset_seconds_never_goes_negative(): void
    {
        $limit = new RateLimit(100, 0, -3.0);

        $this->assertSame(0.0, $limit->resetSeconds());
    }

    #[Test]
    public function it_reports_exhaustion(): void
    {
        $this->assertTrue((new RateLimit(100, 0, 1.0))->isExhausted());
        $this->assertFalse((new RateLimit(100, 1, 1.0))->isExhausted());
    }
}
