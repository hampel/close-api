<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Resource\Activity;

use Hampel\CloseApi\Http\Response;

/**
 * Call activities — `/activity/call/`.
 *
 * @see https://developer.close.com/api/resources/activities/calls
 */
final class Calls extends ActivityResource
{
    /**
     * Per the spec's CallStatus enum.
     */
    public const array STATUSES = [
        'created', 'in-progress', 'completed', 'cancel', 'no-answer', 'busy', 'failed', 'timeout',
    ];

    /**
     * Per the spec's CommunicationDirection enum. "Outgoing means the
     * communication flowing from the user to the lead/contact."
     */
    public const array DIRECTIONS = ['incoming', 'outgoing'];

    protected function kind(): string
    {
        return 'call';
    }

    /**
     * `POST /activity/call/`
     *
     * Logs a call that happened elsewhere. It does not place one.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Response
    {
        return $this->insert($attributes);
    }
}
