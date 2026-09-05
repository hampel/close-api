<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Resource\Activity;

use Hampel\CloseApi\Http\Response;
use Hampel\CloseApi\Pagination\Paginator;
use Hampel\CloseApi\Resource\Resource;

/**
 * Shared shape for the one-kind activity endpoints under `/activity/{kind}/`.
 *
 * Every kind supports list, get, update and delete. Creation does not: a
 * meeting arrives from calendar sync and has no POST, and the kinds that do
 * accept one differ enough in what they require that each subclass declares its
 * own.
 *
 * All activity ids share the `acti_` prefix regardless of kind, so the prefix
 * check here cannot catch a note id passed to an email method. The URL is what
 * distinguishes them.
 */
abstract class ActivityResource extends Resource
{
    /**
     * The path segment under `/activity/`.
     */
    abstract protected function kind(): string;

    final protected function path(): string
    {
        return 'activity/'.$this->kind();
    }

    protected function prefix(): string
    {
        return 'acti_';
    }

    /**
     * One page. `GET /activity/{kind}/`
     *
     * Takes `lead_id`, `contact_id`, `user_id`, `id__in` and the
     * `date_created` / `activity_at` range filters.
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
     * `GET /activity/{kind}/{id}/`
     *
     * @param  array<string, mixed>  $query
     */
    public function get(string $id, array $query = []): Response
    {
        return $this->fetch($id, $query);
    }

    /**
     * `PUT /activity/{kind}/{id}/`
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(string $id, array $attributes): Response
    {
        return $this->change($id, $attributes);
    }

    /**
     * `DELETE /activity/{kind}/{id}/`
     */
    public function delete(string $id): Response
    {
        return $this->remove($id);
    }
}
