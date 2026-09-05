<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Resource;

use Hampel\CloseApi\Exception\InvalidArgumentException;
use Hampel\CloseApi\Http\Response;
use Hampel\CloseApi\Pagination\Paginator;

/**
 * Tasks — `/task/`.
 *
 * @see https://developer.close.com/api/resources/tasks
 */
final class Tasks extends Resource
{
    protected function path(): string
    {
        return 'task';
    }

    protected function prefix(): string
    {
        return 'task_';
    }

    /**
     * One page of tasks. `GET /task/`
     *
     * Takes `lead_id`, `assigned_to`, `is_complete`, `_type`, and date filters
     * on `date`, `due_date`, `date_created` and `date_updated` with the usual
     * `__lt` / `__lte` / `__gt` / `__gte` suffixes.
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
     * `GET /task/{id}/`
     *
     * @param  array<string, mixed>  $query
     */
    public function get(string $id, array $query = []): Response
    {
        return $this->fetch($id, $query);
    }

    /**
     * `POST /task/`
     *
     * `_type` is required. The spec models the payload as a oneOf discriminated
     * on it — `lead` for an ordinary task, `outgoing_call` for a call task —
     * and the two shapes differ, so omitting it is rejected.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Response
    {
        if (! isset($attributes['_type'])) {
            throw new InvalidArgumentException(
                'Creating a task requires "_type" — "lead" for an ordinary task, "outgoing_call" '
                .'for a call task. Close discriminates the payload shape on it.',
            );
        }

        return $this->insert($attributes);
    }

    /**
     * `PUT /task/{id}/`
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(string $id, array $attributes): Response
    {
        return $this->change($id, $attributes);
    }

    /**
     * Update every task matching a filter. `PUT /task/`
     *
     * The filter is a query string and the change is the body — completing all
     * of a lead's tasks is `updateWhere(['lead_id' => $id], ['is_complete' =>
     * true])`.
     *
     * Note there is no dry run and no count returned in advance. An empty
     * filter matches the whole organization, so this refuses one: an accidental
     * bulk edit of every task in Close is not recoverable through this API.
     *
     * @param  array<string, mixed>  $filter
     * @param  array<string, mixed>  $attributes
     */
    public function updateWhere(array $filter, array $attributes): Response
    {
        if ($filter === []) {
            throw new InvalidArgumentException(
                'A bulk task update needs a filter. An empty one matches every task in the '
                .'organization, and there is no way to undo it.',
            );
        }

        return $this->transport->put('task/', $attributes, $filter);
    }

    /**
     * `DELETE /task/{id}/`
     */
    public function delete(string $id): Response
    {
        return $this->remove($id);
    }
}
