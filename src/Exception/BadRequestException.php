<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Exception;

/**
 * 400 — Close rejected the request. Usually a malformed parameter, an
 * unknown field, or a pagination bound exceeded: both _limit and _skip have
 * per-resource maximums that Close does not publish, and crossing either
 * lands here.
 */
class BadRequestException extends ResponseException
{
}
