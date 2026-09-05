<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Resource;

/**
 * The object types that can carry custom fields.
 *
 * The path is `/custom_field/{type}/` — **singular**. This package's
 * predecessor used `custom_fields/`, plural, which is not an endpoint; the
 * OpenAPI spec settles it.
 *
 * These six are the whole set the spec defines, and they are flat. Close's prose
 * documentation describes `activity/<activity_type_id>` and
 * `custom_object/<custom_object_type_id>` forms that do not appear as paths
 * anywhere in the spec; a field's own type restricts it instead.
 */
enum CustomFieldType: string
{
    case Activity = 'activity';
    case Contact = 'contact';
    case CustomObjectType = 'custom_object_type';
    case Lead = 'lead';
    case Opportunity = 'opportunity';

    /**
     * A field defined once and associated with more than one object type.
     * Associations are managed through `CustomFields::associate()`.
     */
    case Shared = 'shared';
}
