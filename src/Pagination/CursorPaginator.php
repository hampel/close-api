<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Pagination;

use Generator;
use Hampel\CloseApi\Exception\BadRequestException;
use Hampel\CloseApi\Exception\CursorExpiredException;
use Hampel\CloseApi\Exception\InvalidArgumentException;
use Hampel\CloseApi\Exception\PaginationLimitException;
use Hampel\CloseApi\Http\Response;
use Hampel\CloseApi\Http\Transport;
use IteratorAggregate;
use Traversable;

/**
 * Walks a cursor-paginated endpoint — the Advanced Filtering API.
 *
 * Close hands back a `cursor` with each page and a null cursor on the last one.
 * Two documented constraints shape everything here:
 *
 * **Cursors expire after 30 seconds.** Not the walk — each individual cursor.
 * So the budget is spent between page fetches, which means a loop doing real
 * work per record (a database write, another API call) works on a small result
 * set and fails on a large one. This paginator measures the gap and, when a
 * page fails after the window has passed, says so rather than passing on an
 * "Expired cursor" whose cause is a hundred lines away.
 *
 * **There is a hard cap of 10,000 objects.** Reaching it with a cursor still
 * outstanding raises PaginationLimitException rather than returning quietly —
 * a caller who asked for every match and got the first 10,000 cannot otherwise
 * tell a complete answer from a truncated one.
 *
 * Close also advises sorting by something stable such as `date_created`:
 * without it, records can move between pages as the sort order shifts and be
 * missed entirely.
 *
 * @implements IteratorAggregate<int, mixed>
 */
final class CursorPaginator implements IteratorAggregate
{
    /**
     * Objects Close will return for one paginated query, whatever the page size.
     */
    public const int HARD_LIMIT = 10_000;

    /**
     * Seconds a cursor stays valid after being issued.
     */
    public const float CURSOR_TTL = 30.0;

    /**
     * @param  array<string, mixed>  $payload  The search body, without `cursor` or `_limit`.
     * @param  (callable(): float)|null  $clock  Overridable for tests; defaults to microtime.
     */
    public function __construct(
        private readonly Transport $transport,
        private readonly string $path,
        private readonly array $payload,
        private readonly int $pageSize = 100,
        private readonly ?int $max = null,
        private $clock = null,
    ) {
        if ($pageSize < 1) {
            throw new InvalidArgumentException('Page size must be at least 1.');
        }

        if ($max !== null && $max < 1) {
            throw new InvalidArgumentException('Maximum records must be at least 1, or null for no limit.');
        }

        if (isset($this->payload['cursor']) || isset($this->payload['_limit'])) {
            throw new InvalidArgumentException(
                'Pass the page size to the paginator rather than as cursor or _limit in the payload.',
            );
        }
    }

    /**
     * @return Generator<int, mixed>
     */
    public function getIterator(): Traversable
    {
        $seen = 0;

        foreach ($this->pages() as $page) {
            foreach ($page->data() as $record) {
                yield $record;

                if (++$seen === $this->max) {
                    return;
                }
            }
        }
    }

    /**
     * @return Generator<int, Response>
     */
    public function pages(): Generator
    {
        $cursor = null;
        $fetched = 0;
        $issuedAt = null;

        while (true) {
            $body = $this->payload + ['_limit' => $this->pageSize];

            if ($cursor !== null) {
                $body['cursor'] = $cursor;
            }

            $page = $this->fetch($body, $issuedAt);
            $issuedAt = $this->now();

            yield $page;

            $fetched += count($page->data());
            $cursor = $page->cursor();

            if ($cursor === null) {
                return;
            }

            if ($this->max !== null && $fetched >= $this->max) {
                return;
            }

            if ($fetched >= self::HARD_LIMIT) {
                throw new PaginationLimitException(
                    sprintf(
                        'The Advanced Filtering API returns at most %s objects for one query, and '
                        .'this one has more. Narrow it — Close suggests splitting on a date_created '
                        .'range and running the query once per slice — or use the Export API.',
                        number_format(self::HARD_LIMIT),
                    ),
                    self::HARD_LIMIT,
                    $fetched,
                );
            }
        }
    }

    /**
     * Every matching record, as an array.
     *
     * @return list<mixed>
     */
    public function all(): array
    {
        return iterator_to_array($this, preserve_keys: false);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function fetch(array $body, ?float $issuedAt): Response
    {
        try {
            return $this->transport->post($this->path, $body);
        } catch (BadRequestException $e) {
            $elapsed = $issuedAt === null ? 0.0 : $this->now() - $issuedAt;

            if ($issuedAt === null || ! $this->looksExpired($e, $elapsed)) {
                throw $e;
            }

            throw new CursorExpiredException(
                sprintf(
                    'The search cursor expired: %.1f seconds passed since Close issued it, and they '
                    .'are valid for %d. Buffer each page before doing per-record work, or re-run '
                    .'the query. The original error was: %s',
                    $elapsed,
                    (int) self::CURSOR_TTL,
                    $e->getMessage(),
                ),
                $e->status(),
                $e->body(),
                $e->rawBody(),
                $e->method(),
                $e->uri(),
                $elapsed,
                $e->rateLimit(),
            );
        }
    }

    /**
     * Whether a 400 is plausibly the cursor having expired.
     *
     * Close's documented wording is "Expired cursor", but the error body shape
     * is not documented at all, so the elapsed time is the more reliable of the
     * two signals and either is enough.
     */
    private function looksExpired(BadRequestException $e, float $elapsed): bool
    {
        return $elapsed > self::CURSOR_TTL
            || stripos($e->getMessage(), 'cursor') !== false
            || stripos($e->rawBody(), 'cursor') !== false;
    }

    private function now(): float
    {
        return $this->clock === null ? microtime(true) : ($this->clock)();
    }
}
