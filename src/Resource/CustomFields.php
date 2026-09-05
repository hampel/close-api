<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Resource;

use Hampel\CloseApi\Exception\InvalidArgumentException;
use Hampel\CloseApi\Http\Response;
use Hampel\CloseApi\Http\Transport;
use Hampel\CloseApi\Pagination\Paginator;

/**
 * Custom field definitions — `/custom_field/{type}/`.
 *
 * These are the field *schemas*, not the values. A custom field's value arrives
 * on the object it belongs to, under a key of the form `custom.cf_xxxxxxxx`,
 * which is why `Response` has no dot notation.
 *
 * @see https://developer.close.com/api/resources/custom-fields
 */
final class CustomFields extends Resource
{
    public function __construct(Transport $transport, private readonly CustomFieldType $type)
    {
        parent::__construct($transport);
    }

    public function type(): CustomFieldType
    {
        return $this->type;
    }

    protected function path(): string
    {
        return 'custom_field/'.$this->type->value;
    }

    protected function prefix(): string
    {
        return 'cf_';
    }

    /**
     * `GET /custom_field/{type}/`
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
     * `GET /custom_field/{type}/{id}/`
     *
     * @param  array<string, mixed>  $query
     */
    public function get(string $id, array $query = []): Response
    {
        return $this->fetch($id, $query);
    }

    /**
     * `POST /custom_field/{type}/`
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Response
    {
        return $this->insert($attributes);
    }

    /**
     * `PUT /custom_field/{type}/{id}/`
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(string $id, array $attributes): Response
    {
        return $this->change($id, $attributes);
    }

    /**
     * `DELETE /custom_field/{type}/{id}/`
     *
     * Removes the field and every value stored in it, on every object.
     */
    public function delete(string $id): Response
    {
        return $this->remove($id);
    }

    /**
     * Associate a shared field with an object type.
     * `POST /custom_field/shared/{id}/association/`
     *
     * Only shared fields have associations; the endpoint exists nowhere else.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function associate(string $id, array $attributes): Response
    {
        $this->requireShared(__FUNCTION__);

        return $this->transport->post('custom_field/shared/'.$this->identifier($id).'/association/', $attributes);
    }

    /**
     * `GET /custom_field/shared/{id}/association/{object_type}/`
     */
    public function association(string $id, CustomFieldType $objectType): Response
    {
        $this->requireShared(__FUNCTION__);

        return $this->transport->get(
            'custom_field/shared/'.$this->identifier($id).'/association/'.$objectType->value.'/',
        );
    }

    /**
     * `DELETE /custom_field/shared/{id}/association/{object_type}/`
     */
    public function disassociate(string $id, CustomFieldType $objectType): Response
    {
        $this->requireShared(__FUNCTION__);

        return $this->transport->delete(
            'custom_field/shared/'.$this->identifier($id).'/association/'.$objectType->value.'/',
        );
    }

    private function requireShared(string $method): void
    {
        if ($this->type !== CustomFieldType::Shared) {
            throw new InvalidArgumentException(sprintf(
                '%s() applies to shared custom fields only; this resource is for "%s" fields.',
                $method,
                $this->type->value,
            ));
        }
    }
}
