<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Resource;

use Hampel\CloseApi\Exception\InvalidArgumentException;
use Hampel\CloseApi\Http\Response;
use Hampel\CloseApi\Http\Transport;
use Hampel\CloseApi\Pagination\CursorPaginator;

/**
 * The Advanced Filtering API — `POST /data/search/`.
 *
 * The way to find leads or contacts by anything other than an exact id. The
 * query is a tree of typed nodes rather than a query string:
 *
 *     $close->search()->paginate([
 *         'type' => 'and',
 *         'queries' => [
 *             ['type' => 'object_type', 'object_type' => 'contact'],
 *             [
 *                 'type' => 'field_condition',
 *                 'field' => ['type' => 'regular_field', 'object_type' => 'contact', 'field_name' => 'title'],
 *                 'condition' => ['type' => 'text', 'mode' => 'full_words', 'value' => 'CEO'],
 *             ],
 *         ],
 *     ]);
 *
 * **This endpoint is absent from Close's OpenAPI spec.** It is documented in
 * prose and it works, but nothing in the machine-readable description mentions
 * it — worth knowing before trusting that spec as a complete inventory.
 *
 * Results are cursor-paginated, capped at 10,000 objects per query, and the
 * cursors expire in 30 seconds. `CursorPaginator` carries the detail.
 *
 * @see https://developer.close.com/api/resources/advanced-filtering
 */
final class Search
{
    public const string PATH = 'data/search/';

    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * One page of results. `POST /data/search/`
     *
     * @param  array<string, mixed>  $query  The query tree.
     * @param  array<string, mixed>  $options  `sort`, `_fields`, `_limit`, `cursor`, `include_counts`.
     */
    public function query(array $query, array $options = []): Response
    {
        if ($query === []) {
            throw new InvalidArgumentException('A search needs a query.');
        }

        return $this->transport->post(self::PATH, ['query' => $query] + $options);
    }

    /**
     * Walk every match, fetching pages as needed.
     *
     * Close advises sorting by a field that does not change, such as
     * `date_created`: without a stable sort, records move between pages as the
     * order shifts and are missed.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $options
     */
    public function paginate(
        array $query,
        array $options = [],
        int $pageSize = 100,
        ?int $max = null,
    ): CursorPaginator {
        if ($query === []) {
            throw new InvalidArgumentException('A search needs a query.');
        }

        return new CursorPaginator(
            $this->transport,
            self::PATH,
            ['query' => $query] + $options,
            $pageSize,
            $max,
        );
    }
}
