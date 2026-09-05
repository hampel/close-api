<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Exception;

/**
 * 403 — authenticated, but not allowed to do this. The key's user lacks the
 * permission, or the object belongs to another organization.
 */
class ForbiddenException extends ResponseException
{
}
