<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Exception;

use Hampel\CloseApi\Http\RateLimit;

/**
 * Close answered, and the answer was an error status.
 *
 * Subclassed per status so a consumer can catch the case it can do something
 * about — a 404 on an optional lookup, a 429 it wants to handle itself — without
 * catching everything.
 *
 * **The error body shape is not documented.** Close's published documentation
 * lists the status codes and stops there, and its OpenAPI spec gives error
 * responses a description and no schema. So the decoded body is exposed as-is
 * through `body()` and the message is assembled defensively from whichever of
 * the plausible keys is present, falling back to the status line. Nothing here
 * asserts a structure, because nothing has verified one. Settling it is a
 * question for the harness — see `harness/`.
 */
class ResponseException extends RuntimeException
{
    /**
     * @param  array<array-key, mixed>  $body
     */
    public function __construct(
        string $message,
        private readonly int $status,
        private readonly array $body,
        private readonly string $rawBody,
        private readonly string $method,
        private readonly string $uri,
        private readonly ?RateLimit $rateLimit = null,
    ) {
        parent::__construct($message, $status);
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * The decoded error body, or an empty array if the body was not JSON.
     *
     * @return array<array-key, mixed>
     */
    public function body(): array
    {
        return $this->body;
    }

    /**
     * The error body exactly as it arrived.
     */
    public function rawBody(): string
    {
        return $this->rawBody;
    }

    public function method(): string
    {
        return $this->method;
    }

    /**
     * The request URI, with any query string.
     */
    public function uri(): string
    {
        return $this->uri;
    }

    public function rateLimit(): ?RateLimit
    {
        return $this->rateLimit;
    }

    /**
     * Per-field validation messages, if the body carried any.
     *
     * Close has been observed to use `field-errors`, but this is not in the
     * documentation and not in the spec, so an empty array here means "nothing
     * recognisable was present" rather than "there were no field errors".
     *
     * @return array<string, mixed>
     */
    public function fieldErrors(): array
    {
        $errors = $this->body['field-errors'] ?? $this->body['field_errors'] ?? null;

        if (! is_array($errors)) {
            return [];
        }

        /** @var array<string, mixed> $errors */
        return $errors;
    }
}
