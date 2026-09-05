<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Resource;

use Hampel\CloseApi\Http\Response;
use Hampel\CloseApi\Pagination\Paginator;

/**
 * The combined activity feed — `/activity/`.
 *
 * Every kind of activity on a lead in one list, newest first, which is what the
 * timeline in the Close UI shows. Read only: an activity is created through the
 * endpoint for its own kind.
 *
 * @see https://developer.close.com/api/resources/activities
 */
final class Activities extends Resource
{
    protected function path(): string
    {
        return 'activity';
    }

    protected function prefix(): string
    {
        return 'acti_';
    }

    /**
     * One page of the feed. `GET /activity/`
     *
     * Takes `lead_id`, `contact_id`, `user_id`, `id__in`, `_type`, and the
     * `date_created` and `activity_at` range filters with `__gt`, `__gte`,
     * `__lt` and `__lte` suffixes.
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
}
