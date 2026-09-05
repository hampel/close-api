<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Resource;

use Hampel\CloseApi\Http\Response;
use Hampel\CloseApi\Pagination\Paginator;

/**
 * Contacts — `/contact/`.
 *
 * @see https://developer.close.com/api/resources/contacts
 */
final class Contacts extends Resource
{
    protected function path(): string
    {
        return 'contact';
    }

    protected function prefix(): string
    {
        return 'cont_';
    }

    /**
     * One page of contacts. `GET /contact/`
     *
     * Takes `lead_id` to restrict to one lead's contacts.
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
     * `GET /contact/{id}/`
     *
     * @param  array<string, mixed>  $query
     */
    public function get(string $id, array $query = []): Response
    {
        return $this->fetch($id, $query);
    }

    /**
     * `POST /contact/`
     *
     * Requires `lead_id`: a contact belongs to a lead and cannot be created
     * without one.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Response
    {
        return $this->insert($attributes);
    }

    /**
     * `PUT /contact/{id}/`
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(string $id, array $attributes): Response
    {
        return $this->change($id, $attributes);
    }

    /**
     * `DELETE /contact/{id}/`
     */
    public function delete(string $id): Response
    {
        return $this->remove($id);
    }
}
