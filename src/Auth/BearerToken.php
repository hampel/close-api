<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Auth;

use Hampel\CloseApi\Exception\InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use SensitiveParameter;

/**
 * An OAuth 2.0 access token, for an integration acting on behalf of a Close
 * user rather than using an organization's own key.
 *
 * This package does not run the authorization code flow or refresh the token —
 * both belong to the application that holds the client secret and the user
 * session. Construct a new instance when the token is refreshed.
 */
final readonly class BearerToken implements Authentication
{
    private string $token;

    public function __construct(#[SensitiveParameter] string $token)
    {
        $token = trim($token);

        if ($token === '') {
            throw new InvalidArgumentException('An OAuth access token is required.');
        }

        $this->token = $token;
    }

    public function authenticate(RequestInterface $request): RequestInterface
    {
        return $request->withHeader('Authorization', 'Bearer '.$this->token);
    }

    public function describe(): string
    {
        return sprintf('bearer token (%d chars)', strlen($this->token));
    }
}
