<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Exception;

/**
 * 402 — a limit of the organization's Close plan was reached. Not a rate
 * limit and not retryable: the account has to change.
 */
class PaymentRequiredException extends ResponseException
{
}
