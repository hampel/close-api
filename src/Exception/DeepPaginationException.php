<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Exception;

/**
 * A page beyond the first was rejected while paginating.
 *
 * Close caps both `_limit` and `_skip`, per resource, and publishes neither
 * number. Crossing either returns a 400 whose message does not necessarily say
 * so, which is how a working import turns into an inscrutable failure once a
 * collection grows past some unknown size.
 *
 * This is a BadRequestException, so existing handling still catches it. What it
 * adds is the context: how far the walk had got, and the fact that Close's
 * documented answer to a long collection is not a bigger page number. Chunk the
 * query by `date_created` range, or use the Export API.
 *
 * It is not proof of the skip cap - a query can also become invalid for reasons
 * of its own - which is why the message says "likely" and hands back the
 * original body.
 */
final class DeepPaginationException extends BadRequestException
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
        private readonly int $skip,
        private readonly int $pagesFetched,
        ?\Hampel\CloseApi\Http\RateLimit $rateLimit = null,
    ) {
        parent::__construct($message, $status, $body, $rawBody, $method, $uri, $rateLimit);
    }

    /**
     * The `_skip` value that was rejected.
     */
    public function skip(): int
    {
        return $this->skip;
    }

    /**
     * How many pages had been read successfully before this one.
     */
    public function pagesFetched(): int
    {
        return $this->pagesFetched;
    }
}
