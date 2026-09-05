<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Resource\Activity;

use Hampel\CloseApi\Http\Response;

/**
 * Note activities — `/activity/note/`.
 *
 * @see https://developer.close.com/api/resources/activities/notes
 */
final class Notes extends ActivityResource
{
    protected function kind(): string
    {
        return 'note';
    }

    /**
     * `POST /activity/note/`
     *
     * Requires `lead_id` and one of `note` (plain text) or `note_html`.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Response
    {
        return $this->insert($attributes);
    }
}
