<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Tests\Double;

use Hampel\CloseApi\Exception\RuntimeException;
use Hampel\CloseApi\Http\RetryPolicy;
use Psr\Http\Message\RequestInterface;

/**
 * The default policy for tests that are not about retrying.
 *
 * Most of the suite asserts on the request that was built or the exception that
 * came out, and a retry would double the first and delay the second. Tests that
 * are about retrying pass DefaultRetryPolicy explicitly.
 */
final class NeverRetry implements RetryPolicy
{
    public function retryAfter(int $attempt, RequestInterface $request, RuntimeException $error): ?float
    {
        return null;
    }
}
