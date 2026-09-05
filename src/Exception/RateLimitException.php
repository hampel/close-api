<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Exception;

use Hampel\CloseApi\Http\RateLimit;

/**
 * 429 — too many requests for this endpoint group.
 *
 * Close enforces limits per endpoint group rather than globally, and per
 * organization as well as per API key, so this can arrive because of traffic
 * from a process that is not this one.
 *
 * The transport retries these on its own by default, so a consumer only sees
 * this once the retry budget is spent. `waitSeconds()` is how long Close said
 * to wait; honour it rather than backing off on a schedule of your own.
 */
class RateLimitException extends ResponseException
{
    /**
     * @param  array<array-key, mixed>  $body
     */
    public function __construct(
        string $message,
        int $status,
        array $body,
        string $rawBody,
        string $method,
        string $uri,
        ?RateLimit $rateLimit = null,
        private readonly ?float $retryAfter = null,
    ) {
        parent::__construct($message, $status, $body, $rawBody, $method, $uri, $rateLimit);
    }

    /**
     * The `Retry-After` header, in seconds, if it was present.
     *
     * Every 429 is documented to carry one. It is the RateLimit header's reset
     * value rounded up to the next whole second, which is why it is the second
     * choice rather than the first.
     */
    public function retryAfter(): ?float
    {
        return $this->retryAfter;
    }

    /**
     * How long to wait before retrying, in seconds.
     *
     * Prefers the RateLimit header's `reset`, which is a decimal and therefore
     * more precise than `Retry-After`; Close's own documentation recommends it
     * for that reason. Falls back to `Retry-After`, then to one second, so a
     * caller always gets a usable number.
     */
    public function waitSeconds(): float
    {
        return $this->rateLimit()?->resetSeconds()
            ?? $this->retryAfter
            ?? 1.0;
    }
}
