<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Http;

use Hampel\CloseApi\Exception\RuntimeException;
use Psr\Http\Message\RequestInterface;

/**
 * Never retries. Every failure reaches the caller as the exception it was.
 *
 * The default policy retries a 429 by sleeping for as long as Close asks, in
 * the middle of the request. That is right for a script, and wrong wherever the
 * calling process is a resource someone else is waiting for:
 *
 * - a queue worker, which is blocked for the duration and cannot pick up other
 *   jobs — the job wants to release itself and be retried later instead;
 * - a web request, where a caller is holding a connection open;
 * - anything with its own backoff, which would then be applied on top of this
 *   one rather than instead of it.
 *
 * In all three the retry decision belongs to the consumer, and it needs the
 * failure promptly to make it. Catch `RateLimitException` and read
 * `waitSeconds()` for how long Close asked to wait.
 *
 * Reaching this by way of `new DefaultRetryPolicy(maxAttempts: 1)` works and
 * says nothing about intent; this says what it is for.
 */
final class NoRetryPolicy implements RetryPolicy
{
    public function retryAfter(int $attempt, RequestInterface $request, RuntimeException $error): ?float
    {
        return null;
    }
}
