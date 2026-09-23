<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Resource;

/**
 * The object types that can carry custom fields.
 *
 * The path is `/custom_field/{type}/` — **singular**, which is what the OpenAPI
 * spec documents. The plural `custom_fields/{type}/` also answers 200 (measured
 * 2026-09-23) but appears nowhere in the spec, so it is an undocumented alias
 * and this package does not rely on it.
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
