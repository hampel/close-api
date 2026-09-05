<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Exception;

/**
 * Something went wrong while talking to Close.
 */
class RuntimeException extends \RuntimeException implements CloseApiException
{
}
