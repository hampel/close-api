<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Exception;

/**
 * The request never produced a response — DNS failure, connection refused,
 * TLS failure, timeout.
 *
 * Wraps the underlying PSR-18 ClientExceptionInterface, which is available
 * through getPrevious(). Distinct from ResponseException on purpose: Close
 * said nothing here, so there is no status code and no body to inspect, and
 * retrying may well succeed.
 */
class TransportException extends RuntimeException
{
}
