<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Http;

use Hampel\CloseApi\Exception\RuntimeException;
use Psr\Http\Message\RequestInterface;

/**
 * Decides whether a failed request is worth trying again, and how long to wait
 * first.
 */
interface RetryPolicy
{
    /**
     * Seconds to wait before the next attempt, or null to give up and let the
     * error propagate.
     *
     * @param  int  $attempt  1-based number of the attempt that just failed.
     * @param  RuntimeException  $error  A ResponseException or a TransportException.
     */
    public function retryAfter(int $attempt, RequestInterface $request, RuntimeException $error): ?float;
}
