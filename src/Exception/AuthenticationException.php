<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Exception;

/**
 * 401 — the request was not authenticated. A missing, malformed, revoked or
 * wrong-organization API key.
 */
class AuthenticationException extends ResponseException
{
}
