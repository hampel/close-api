<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Auth;

use Psr\Http\Message\RequestInterface;

/**
 * Applies credentials to an outgoing request.
 *
 * Close supports two schemes — an API key over HTTP Basic, and an OAuth 2.0
 * access token — and an integration distributed to other people has to use the
 * second. Only the first is exercised here today, but the seam costs nothing
 * now and would be a breaking change to add later.
 */
interface Authentication
{
    public function authenticate(RequestInterface $request): RequestInterface;

    /**
     * A description of the credential safe to write to a log.
     *
     * Implementations must never return the secret itself. The transport logs
     * this, and a log is exactly where a working key must not end up.
     */
    public function describe(): string;
}
