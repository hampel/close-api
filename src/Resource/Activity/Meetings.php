<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Resource\Activity;

/**
 * Meeting activities — `/activity/meeting/`.
 *
 * Read, update and delete only. Meetings are synced from Google Calendar or
 * Outlook, or created in the Close UI; `/activity/meeting/` has no POST, so
 * there is deliberately no create() here.
 *
 * @see https://developer.close.com/api/resources/activities/meetings
 */
final class Meetings extends ActivityResource
{
    protected function kind(): string
    {
        return 'meeting';
    }
}
