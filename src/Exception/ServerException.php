<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Exception;

/**
 * A 5xx from Close.
 *
 * Nothing about the request is necessarily wrong, so the transport retries
 * these; a consumer only sees one when the retry budget is spent.
 *
 * Note that a write which returns 5xx may still have been applied — the failure
 * can be in the response path rather than the operation. Retrying a create on
 * this is how duplicates get made, which is why the default retry policy leaves
 * non-idempotent methods alone.
 */
class ServerException extends ResponseException
{
}
