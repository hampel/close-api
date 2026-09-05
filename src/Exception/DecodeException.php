<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Exception;

/**
 * Close returned a success status with a body that is not the JSON object
 * this package expects.
 *
 * Almost always means a proxy, a captive portal or an error page has been
 * substituted for the API response, so the raw body is kept for inspection
 * rather than discarded.
 */
class DecodeException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $body,
        private readonly int $status,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The undecodable response body, verbatim.
     */
    public function body(): string
    {
        return $this->body;
    }

    public function status(): int
    {
        return $this->status;
    }
}
