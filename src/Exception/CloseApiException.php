<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Exception;

use Throwable;

/**
 * Marker interface implemented by every exception this package throws.
 *
 * Lets a consumer catch everything from this package with one catch block
 * without also catching unrelated SPL exceptions raised by their own code.
 */
interface CloseApiException extends Throwable
{
}
