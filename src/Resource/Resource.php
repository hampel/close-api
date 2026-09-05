<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Resource;

use Hampel\CloseApi\Exception\InvalidArgumentException;
use Hampel\CloseApi\Http\Response;
use Hampel\CloseApi\Http\Transport;
use Hampel\CloseApi\Pagination\Paginator;

/**
 * Base for the endpoint groups.
 *
 * A resource builds paths and payloads and delegates. It never touches HTTP,
 * which is what makes it cheap to test exhaustively: the assertion is on the
 * request that came out, not on a response that had to be faked.
 *
 * The helpers here are protected rather than public on purpose. Not every
 * resource supports every operation — `/user/` has no create, `/activity/
 * meeting/` has no POST because meetings arrive from calendar sync — and a
 * subclass exposing only what the API actually offers turns "that endpoint does
 * not exist" from a 405 at runtime into a missing method at author time.
 */
abstract class Resource
{
    public function __construct(protected readonly Transport $transport)
    {
    }

    /**
     * The collection path, with no leading or trailing slash: `lead`,
     * `activity/note`, `custom_field/lead`.
     */
    abstract protected function path(): string;

    /**
     * The prefix Close puts on this resource's ids — `lead_`, `cont_` — or null
     * where there is nothing stable to check.
     */
    abstract protected function prefix(): ?string;

    /**
     * @param  array<string, mixed>  $query
     */
    protected function listing(array $query = []): Response
    {
        return $this->transport->get($this->path().'/', $query);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    protected function fetch(string $id, array $query = []): Response
    {
        return $this->transport->get($this->path().'/'.$this->identifier($id).'/', $query);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function insert(array $attributes): Response
    {
        return $this->transport->post($this->path().'/', $attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function change(string $id, array $attributes): Response
    {
        return $this->transport->put($this->path().'/'.$this->identifier($id).'/', $attributes);
    }

    protected function remove(string $id): Response
    {
        return $this->transport->delete($this->path().'/'.$this->identifier($id).'/');
    }

    /**
     * @param  array<string, mixed>  $query
     */
    protected function pages(array $query = [], int $pageSize = Paginator::DEFAULT_PAGE_SIZE, ?int $max = null): Paginator
    {
        return new Paginator($this->transport, $this->path().'/', $query, $pageSize, $max);
    }

    /**
     * Check an id looks like one of this resource's, and hand it back.
     *
     * Close prefixes ids by kind and the prefixes are visible throughout its
     * own documentation and spec examples. Passing a contact id to a lead
     * method is a real and easy mistake, and Close answers it with a 404 that
     * says nothing about the cause.
     *
     * This is a guard against transposition, not a parser: anything with the
     * right prefix is passed through untouched.
     */
    protected function identifier(string $id): string
    {
        $id = trim($id);

        if ($id === '') {
            throw new InvalidArgumentException(
                sprintf('An id is required for %s.', $this->path()),
            );
        }

        $prefix = $this->prefix();

        if ($prefix !== null && ! str_starts_with($id, $prefix)) {
            throw new InvalidArgumentException(sprintf(
                'Expected a %s id starting with "%s", got "%s". Close prefixes ids by kind, so this '
                .'is usually an id belonging to a different resource.',
                $this->path(),
                $prefix,
                $id,
            ));
        }

        return $id;
    }
}
