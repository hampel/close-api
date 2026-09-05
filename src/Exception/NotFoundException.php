<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Exception;

/**
 * 404 — no such object, or no such endpoint. Worth catching on its own: a
 * 404 on an optional lookup is often an ordinary answer rather than a
 * failure.
 */
class NotFoundException extends ResponseException
{
}
