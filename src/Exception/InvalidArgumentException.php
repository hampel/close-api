<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Exception;

/**
 * A value handed to this package was unusable before any request was made.
 */
class InvalidArgumentException extends \InvalidArgumentException implements CloseApiException
{
}
