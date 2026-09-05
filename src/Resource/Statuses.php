<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Resource;

use Hampel\CloseApi\Http\Response;
use Hampel\CloseApi\Http\Transport;

/**
 * Lead and opportunity statuses — `/status/lead/` and `/status/opportunity/`.
 *
 * Both kinds use the `stat_` id prefix, so an id alone does not say which it
 * belongs to; the resource does.
 */
final class Statuses extends Resource
{
    public function __construct(Transport $transport, private readonly StatusType $type)
    {
        parent::__construct($transport);
    }

    public function type(): StatusType
    {
        return $this->type;
    }

    protected function path(): string
    {
        return 'status/'.$this->type->value;
    }

    protected function prefix(): string
    {
        return 'stat_';
    }

    /**
     * `GET /status/{type}/`
     *
     * The whole set, and small enough that it does not need paginating.
     *
     * @param  array<string, mixed>  $query
     */
    public function list(array $query = []): Response
    {
        return $this->listing($query);
    }

    /**
     * `GET /status/{type}/{id}/`
     *
     * @param  array<string, mixed>  $query
     */
    public function get(string $id, array $query = []): Response
    {
        return $this->fetch($id, $query);
    }

    /**
     * `POST /status/{type}/`
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Response
    {
        return $this->insert($attributes);
    }

    /**
     * `PUT /status/{type}/{id}/`
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(string $id, array $attributes): Response
    {
        return $this->change($id, $attributes);
    }

    /**
     * `DELETE /status/{type}/{id}/`
     */
    public function delete(string $id): Response
    {
        return $this->remove($id);
    }
}
