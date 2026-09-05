<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Auth;

use Hampel\CloseApi\Exception\InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use SensitiveParameter;

/**
 * HTTP Basic authentication with the API key as the username and an empty
 * password, which is what Close documents for API keys.
 *
 * Note the trailing colon in the encoded credential: `apikey:` with nothing
 * after it. Omitting it produces a 401 that looks like a bad key.
 */
final readonly class ApiKey implements Authentication
{
    private string $key;

    public function __construct(#[SensitiveParameter] string $key)
    {
        $key = trim($key);

        if ($key === '') {
            throw new InvalidArgumentException('A Close API key is required.');
        }

        $this->key = $key;
    }

    public function authenticate(RequestInterface $request): RequestInterface
    {
        return $request->withHeader(
            'Authorization',
            'Basic '.base64_encode($this->key.':'),
        );
    }

    /**
     * The key's prefix only.
     *
     * Close keys are prefixed by kind — `api_` for a personal key — which is
     * the part worth having in a log when a 401 needs explaining, and the rest
     * is the part that must not be there.
     */
    public function describe(): string
    {
        $prefix = substr($this->key, 0, 4);

        return sprintf('api key %s… (%d chars)', $prefix, strlen($this->key));
    }
}
