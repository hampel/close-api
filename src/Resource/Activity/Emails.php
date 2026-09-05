<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Resource\Activity;

use Hampel\CloseApi\Exception\InvalidArgumentException;
use Hampel\CloseApi\Http\Response;

/**
 * Email activities — `/activity/email/`.
 *
 * @see https://developer.close.com/api/resources/activities/emails
 */
final class Emails extends ActivityResource
{
    /**
     * The states an email can be created in, per the spec's CreateEmailActivity
     * schema.
     *
     * `outbox` is not a state, it is an instruction: it queues the message and
     * Close sends it. Everything else records an email without transmitting
     * anything. Logging historic correspondence uses `sent` or `inbox`; a
     * message for a human to review before it goes uses `draft`.
     */
    public const array STATUSES = ['inbox', 'draft', 'scheduled', 'outbox', 'sent', 'error'];

    protected function kind(): string
    {
        return 'email';
    }

    /**
     * `POST /activity/email/`
     *
     * Requires `lead_id` and `status` — and only those two, per the spec.
     *
     * **`status => 'outbox'` sends the email.** It is a real message to a real
     * person, and there is no recall. The check below is for a status Close
     * would reject anyway; the one worth making is your own, that you meant
     * `outbox` rather than `draft`.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Response
    {
        $status = $attributes['status'] ?? null;

        if (! is_string($status) || ! in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException(sprintf(
                'Creating an email requires "status", one of: %s. Note that "outbox" sends the '
                .'message; every other value only records it.',
                implode(', ', self::STATUSES),
            ));
        }

        return $this->insert($attributes);
    }
}
