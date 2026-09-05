<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Http;

use ArrayAccess;
use ArrayIterator;
use Countable;
use Hampel\CloseApi\Exception\RuntimeException;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * The decoded body of one successful Close response, plus what came off the
 * envelope with it.
 *
 * One type for every endpoint. There are no per-resource response classes,
 * because Close's OpenAPI spec omits a response schema for well over half its
 * operations — typing those would mean inferring a structure from a single
 * example and presenting the inference as a contract. An array that is honestly
 * untyped beats a class that is confidently wrong. Typed entities can be added
 * per resource later without breaking this.
 *
 * ArrayAccess, Countable and iteration all operate on the top level of the
 * decoded body, so they agree with each other and with `all()`.
 *
 * **Keys are literal.** There is no dot notation, and there will not be: Close
 * names custom fields `custom.cf_xxxxxxxx`, so a dot is a character inside a
 * key here rather than a separator between them.
 *
 * @implements ArrayAccess<array-key, mixed>
 * @implements IteratorAggregate<array-key, mixed>
 */
final class Response implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
    /**
     * @param  array<array-key, mixed>  $body
     */
    public function __construct(
        private readonly array $body,
        public readonly int $status,
        public readonly ?RateLimit $rateLimit = null,
    ) {
    }

    /**
     * The whole decoded body.
     *
     * @return array<array-key, mixed>
     */
    public function all(): array
    {
        return $this->body;
    }

    /**
     * The records this response carries.
     *
     * A list endpoint wraps them in `data`; a single-object endpoint is the
     * object. This returns the former when it is present and the latter
     * otherwise, so a caller that only wants "what came back" does not have to
     * know which shape the endpoint used.
     *
     * @return array<array-key, mixed>
     */
    public function data(): array
    {
        if ($this->isList()) {
            /** @var array<array-key, mixed> $data */
            $data = $this->body['data'];

            return $data;
        }

        return $this->body;
    }

    /**
     * Whether this is a list response.
     *
     * A `data` array alone is not enough: an object could legitimately have a
     * field called `data`. What makes it an envelope is the pagination marker
     * beside it — `has_more` for the offset endpoints, `cursor` for the two
     * that paginate by cursor. Both shapes count, because both are list
     * responses.
     */
    public function isList(): bool
    {
        return isset($this->body['data'])
            && is_array($this->body['data'])
            && (array_key_exists('has_more', $this->body) || array_key_exists('cursor', $this->body));
    }

    /**
     * Whether Close says there are further pages after this one.
     *
     * False for a response that is not a list, which is the truthful answer:
     * there is nothing more to fetch.
     */
    public function hasMore(): bool
    {
        return (bool) ($this->body['has_more'] ?? false);
    }

    /**
     * The cursor for the next page, on the endpoints that paginate by cursor
     * rather than offset.
     *
     * Null means this is the last page — Close signals the end by sending a
     * null cursor. Advanced Filtering cursors expire 30 seconds after they are
     * issued, so this value cannot be stored, queued, or held while the caller
     * does per-record work.
     */
    public function cursor(): ?string
    {
        $cursor = $this->body['cursor'] ?? null;

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body);
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->body);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->body[$offset] ?? null;
    }

    /**
     * @throws RuntimeException always — a response describes what Close sent.
     */
    public function offsetSet(mixed $offset, mixed $value): never
    {
        throw new RuntimeException('A Close API response is read-only.');
    }

    /**
     * @throws RuntimeException always — a response describes what Close sent.
     */
    public function offsetUnset(mixed $offset): never
    {
        throw new RuntimeException('A Close API response is read-only.');
    }

    public function count(): int
    {
        return count($this->body);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->body);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->body;
    }
}
