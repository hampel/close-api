<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Resource;

use Hampel\CloseApi\Http\Response;
use Hampel\CloseApi\Pagination\Paginator;

/**
 * Leads — `/lead/`.
 *
 * @see https://developer.close.com/api/resources/leads
 */
final class Leads extends Resource
{
    protected function path(): string
    {
        return 'lead';
    }

    protected function prefix(): string
    {
        return 'lead_';
    }

    /**
     * One page of leads. `GET /lead/`
     *
     * For anything beyond the first page or two use `paginate()`, and for a
     * filtered search use the Advanced Filtering API — see `Search`.
     *
     * @param  array<string, mixed>  $query
     */
    public function list(array $query = []): Response
    {
        return $this->listing($query);
    }

    /**
     * Walk the collection, a page at a time. `GET /lead/`
     *
     * @param  array<string, mixed>  $query
     */
    public function paginate(array $query = [], int $pageSize = Paginator::DEFAULT_PAGE_SIZE, ?int $max = null): Paginator
    {
        return $this->pages($query, $pageSize, $max);
    }

    /**
     * `GET /lead/{id}/`
     *
     * @param  array<string, mixed>  $query
     */
    public function get(string $id, array $query = []): Response
    {
        return $this->fetch($id, $query);
    }

    /**
     * `POST /lead/`
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Response
    {
        return $this->insert($attributes);
    }

    /**
     * `PUT /lead/{id}/`
     *
     * Close applies only the fields present, so send only what is changing.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(string $id, array $attributes): Response
    {
        return $this->change($id, $attributes);
    }

    /**
     * `DELETE /lead/{id}/`
     *
     * Takes the lead's contacts, notes, tasks and activities with it.
     */
    public function delete(string $id): Response
    {
        return $this->remove($id);
    }

    /**
     * Merge one lead into another. `POST /lead/merge/`
     *
     * The source is destroyed and its data moved onto the destination. There is
     * no undo.
     */
    public function merge(string $source, string $destination): Response
    {
        return $this->transport->post('lead/merge/', [
            'source' => $this->identifier($source),
            'destination' => $this->identifier($destination),
        ]);
    }
}
