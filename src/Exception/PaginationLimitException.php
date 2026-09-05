<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Exception;

/**
 * A walk stopped at a limit Close imposes, with results left unread.
 *
 * Thrown by the client rather than raised by Close: the Advanced Filtering API
 * documents a hard cap of 10,000 objects per paginated query, and continuing
 * past it is not possible.
 *
 * This is an exception rather than a quiet `return` on purpose. A caller that
 * asked for every match and silently received the first 10,000 has no way to
 * tell a complete answer from a truncated one, and that is the exact shape of
 * bug this package exists to stop repeating.
 */
final class PaginationLimitException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $limit,
        private readonly int $fetched,
    ) {
        parent::__construct($message);
    }

    public function limit(): int
    {
        return $this->limit;
    }

    /**
     * How many records were read before the limit was reached.
     */
    public function fetched(): int
    {
        return $this->fetched;
    }
}
