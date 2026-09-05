<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Resource;

use Hampel\CloseApi\Http\Response;

/**
 * Users — `/user/` and `/me/`.
 *
 * Read only. Users are created by invitation through the Close UI, so there is
 * deliberately no create, update or delete here: the endpoints do not exist.
 */
final class Users extends Resource
{
    protected function path(): string
    {
        return 'user';
    }

    protected function prefix(): string
    {
        return 'user_';
    }

    /**
     * `GET /user/`
     *
     * @param  array<string, mixed>  $query
     */
    public function list(array $query = []): Response
    {
        return $this->listing($query);
    }

    /**
     * `GET /user/{id}/`
     *
     * @param  array<string, mixed>  $query
     */
    public function get(string $id, array $query = []): Response
    {
        return $this->fetch($id, $query);
    }

    /**
     * The user the current credentials belong to. `GET /me/`
     *
     * The cheapest way to check that a key works and to find which organization
     * it is attached to.
     *
     * @param  array<string, mixed>  $query
     */
    public function me(array $query = []): Response
    {
        return $this->transport->get('me/', $query);
    }

    /**
     * `GET /user/availability/`
     */
    public function availability(): Response
    {
        return $this->transport->get('user/availability/');
    }
}
