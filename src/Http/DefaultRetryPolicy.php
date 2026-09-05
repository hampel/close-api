<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Http;

use Hampel\CloseApi\Exception\InvalidArgumentException;
use Hampel\CloseApi\Exception\RateLimitException;
use Hampel\CloseApi\Exception\RuntimeException;
use Hampel\CloseApi\Exception\ServerException;
use Hampel\CloseApi\Exception\TransportException;
use Psr\Http\Message\RequestInterface;

/**
 * Retries the two failures that are worth retrying, and nothing else.
 *
 * **429.** Always retried, whatever the method. A rate limit is applied before
 * the request is processed, so nothing happened and repeating it is safe. The
 * wait comes from Close — the `RateLimit` header's `reset`, falling back to
 * `Retry-After` — rather than from a schedule of our own, because Close knows
 * when its window ends and we are guessing.
 *
 * **5xx and connection failures.** Retried only for idempotent methods, with
 * exponential backoff and full jitter. The asymmetry matters: a POST that fails
 * this way may already have been applied, with the failure in the response path
 * rather than the operation, and retrying it is how duplicate leads get
 * created. A caller who knows their POST is safe to repeat can retry it.
 *
 * Nothing else is retried. A 400, 401, 403, 404 or 415 will fail again in
 * exactly the same way, and burning the budget on it only delays the error.
 *
 * The jitter is full jitter — a uniform draw over the whole interval rather
 * than a fixed delay plus noise — because the failure mode being avoided is
 * every worker that hit the same outage waking at the same moment.
 */
final readonly class DefaultRetryPolicy implements RetryPolicy
{
    /**
     * Methods safe to repeat when we do not know whether the first attempt
     * took effect.
     */
    private const IDEMPOTENT = ['GET', 'HEAD', 'OPTIONS', 'PUT', 'DELETE'];

    /**
     * @param  int  $maxAttempts  Total attempts including the first.
     * @param  float  $baseDelay  Backoff for the first 5xx retry, in seconds.
     * @param  float  $maxDelay  Ceiling on any single wait, in seconds. A rate
     *                           limit window longer than this is reported to
     *                           the caller rather than slept through: a library
     *                           that silently blocks a request thread for
     *                           minutes is worse than one that says why it
     *                           stopped.
     */
    public function __construct(
        private int $maxAttempts = 3,
        private float $baseDelay = 0.5,
        private float $maxDelay = 60.0,
    ) {
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('maxAttempts must be at least 1.');
        }

        if ($baseDelay < 0 || $maxDelay < 0) {
            throw new InvalidArgumentException('Retry delays cannot be negative.');
        }
    }

    public function retryAfter(int $attempt, RequestInterface $request, RuntimeException $error): ?float
    {
        if ($attempt >= $this->maxAttempts) {
            return null;
        }

        if ($error instanceof RateLimitException) {
            $wait = $error->waitSeconds();

            return $wait > $this->maxDelay ? null : $wait;
        }

        if (! $error instanceof ServerException && ! $error instanceof TransportException) {
            return null;
        }

        if (! in_array(strtoupper($request->getMethod()), self::IDEMPOTENT, true)) {
            return null;
        }

        return $this->backoff($attempt);
    }

    /**
     * Full jitter: a uniform draw over [0, base * 2^(attempt-1)], capped.
     */
    private function backoff(int $attempt): float
    {
        $ceiling = min($this->maxDelay, $this->baseDelay * (2 ** ($attempt - 1)));

        if ($ceiling <= 0) {
            return 0.0;
        }

        return $ceiling * (random_int(0, PHP_INT_MAX) / PHP_INT_MAX);
    }
}
