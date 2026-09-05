<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Http;

/**
 * The rate limit state Close reported on a single response.
 *
 * Close sends one RFC-shaped header carrying three values:
 *
 *     RateLimit: limit=100, remaining=50, reset=5
 *
 * `reset` is seconds remaining in the enforcement window, as a decimal — not
 * an integer, and not a timestamp. An older set of `x-rate-limit-*` headers and
 * a copy of the same values in the response body have both been withdrawn; this
 * class reads only the current header.
 *
 * Limits are enforced per endpoint group, and Close neither publishes the
 * groups nor guarantees they are stable, so these values describe the limit
 * this particular request came closest to and cannot be aggregated into a
 * picture of the whole API. They are also enforced per organization as well as
 * per key, which means another process on another key can consume the budget
 * this response just reported.
 *
 * Treat it as an observation, not a budget.
 */
final readonly class RateLimit
{
    public function __construct(
        public int $limit,
        public int $remaining,
        public float $reset,
    ) {
    }

    /**
     * Parse a RateLimit header value, or return null if it is absent or is not
     * in the documented form.
     *
     * Unparseable is deliberately null rather than an exception: the header is
     * informational, Close says only that "most" responses carry it, and losing
     * a successful response because its metadata was malformed would be a poor
     * trade.
     */
    public static function fromHeader(string $header): ?self
    {
        $values = [];

        foreach (explode(',', $header) as $pair) {
            if (! str_contains($pair, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $pair, 2);
            $values[strtolower(trim($key))] = trim($value, " \t\"");
        }

        if (! isset($values['limit'], $values['remaining'], $values['reset'])) {
            return null;
        }

        if (! is_numeric($values['limit']) || ! is_numeric($values['remaining']) || ! is_numeric($values['reset'])) {
            return null;
        }

        return new self(
            (int) $values['limit'],
            (int) $values['remaining'],
            (float) $values['reset'],
        );
    }

    /**
     * Seconds to wait for the current enforcement window to end.
     *
     * Never negative: a stale or clock-skewed value should mean "go now", not
     * an exception somewhere downstream in a sleep call.
     */
    public function resetSeconds(): float
    {
        return max(0.0, $this->reset);
    }

    /**
     * Whether Close reported no requests left in this window.
     *
     * Note the converse does not hold. Some endpoints have stricter,
     * undocumented limits and can return a 429 while `remaining` is still
     * positive, so a false here is not permission to proceed.
     */
    public function isExhausted(): bool
    {
        return $this->remaining <= 0;
    }
}
