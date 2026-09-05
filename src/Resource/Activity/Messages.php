<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Resource\Activity;

use Hampel\CloseApi\Http\Response;

/**
 * SMS activities — `/activity/sms/`.
 *
 * Named Messages rather than Sms because `$close->messages()` reads better than
 * `$close->sms()` and the path is unambiguous either way.
 */
final class Messages extends ActivityResource
{
    protected function kind(): string
    {
        return 'sms';
    }

    /**
     * `POST /activity/sms/`
     *
     * As with email, the status decides whether this records a message or
     * transmits one. Check which you mean.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Response
    {
        return $this->insert($attributes);
    }
}
