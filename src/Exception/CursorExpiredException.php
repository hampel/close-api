<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Exception;

use Hampel\CloseApi\Http\RateLimit;

/**
 * A search cursor was used after it expired.
 *
 * Close expires Advanced Filtering cursors 30 seconds after issuing them. The
 * failure mode is specific and easy to walk into: iterating a search while
 * doing real work per record — a database write, another API call — spends the
 * budget between page fetches rather than during them, so the code works on a
 * small result set and fails on a large one.
 *
 * The fix is to buffer each page before processing it, or to re-run the query.
 * A cursor cannot be stored, queued, or handed to another process.
 */
final class CursorExpiredException extends BadRequestException
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
        private readonly float $elapsed,
        ?RateLimit $rateLimit = null,
    ) {
        parent::__construct($message, $status, $body, $rawBody, $method, $uri, $rateLimit);
    }

    /**
     * Seconds between the cursor being issued and being used.
     */
    public function elapsed(): float
    {
        return $this->elapsed;
    }
}
