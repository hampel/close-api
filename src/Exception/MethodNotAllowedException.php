<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Exception;

/**
 * 405 — the HTTP method is not supported on this path. From a client this
 * means the path or method is wrong in this package, not in the caller's
 * code.
 */
class MethodNotAllowedException extends ResponseException
{
}
