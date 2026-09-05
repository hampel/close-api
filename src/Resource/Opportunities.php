<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Resource;

use Hampel\CloseApi\Http\Response;
use Hampel\CloseApi\Pagination\Paginator;

/**
 * Opportunities — `/opportunity/`.
 *
 * @see https://developer.close.com/api/resources/opportunities
 */
final class Opportunities extends Resource
{
    protected function path(): string
    {
        return 'opportunity';
    }

    protected function prefix(): string
    {
        return 'oppo_';
    }

    /**
     * One page of opportunities. `GET /opportunity/`
     *
     * @param  array<string, mixed>  $query
     */
    public function list(array $query = []): Response
    {
        return $this->listing($query);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public function paginate(array $query = [], int $pageSize = Paginator::DEFAULT_PAGE_SIZE, ?int $max = null): Paginator
    {
        return $this->pages($query, $pageSize, $max);
    }

    /**
     * `GET /opportunity/{id}/`
     *
     * @param  array<string, mixed>  $query
     */
    public function get(string $id, array $query = []): Response
    {
        return $this->fetch($id, $query);
    }

    /**
     * `POST /opportunity/`
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Response
    {
        return $this->insert($attributes);
    }

    /**
     * `PUT /opportunity/{id}/`
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(string $id, array $attributes): Response
    {
        return $this->change($id, $attributes);
    }

    /**
     * `DELETE /opportunity/{id}/`
     */
    public function delete(string $id): Response
    {
        return $this->remove($id);
    }
}
