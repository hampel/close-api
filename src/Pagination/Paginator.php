<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Pagination;

use Generator;
use Hampel\CloseApi\Exception\BadRequestException;
use Hampel\CloseApi\Exception\DeepPaginationException;
use Hampel\CloseApi\Exception\InvalidArgumentException;
use Hampel\CloseApi\Http\Response;
use Hampel\CloseApi\Http\Transport;
use IteratorAggregate;
use Traversable;

/**
 * Walks an offset-paginated list endpoint.
 *
 * Close returns `{"data": [...], "has_more": bool}` and takes `_skip` and
 * `_limit`. What it also does, and does not document, is cap both — per
 * resource, at numbers it does not publish — and return a 400 when either is
 * crossed.
 *
 * So this deliberately does not offer "iterate everything and trust has_more".
 * A loop written that way works until the collection grows past whichever
 * unknown bound applies, and then starts failing. The quieter version of the
 * same mistake — one unlimited call against a default page size of 100 —
 * returns the first hundred records as though they were the whole set.
 *
 * Two things follow. A 400 on any page after the first becomes a
 * DeepPaginationException naming how far the walk had got, rather than an
 * unexplained bad request. And `max` exists so a caller can bound the walk
 * themselves, because for a genuinely large collection the answer is to chunk
 * the query by `date_created` or use the Export API — not a higher page number.
 *
 * @implements IteratorAggregate<int, mixed>
 */
final class Paginator implements IteratorAggregate
{
    /**
     * Close's own default, and what it uses when `_limit` is absent.
     */
    public const int DEFAULT_PAGE_SIZE = 100;

    /**
     * @param  array<string, mixed>  $query  Filters, without `_skip` or `_limit`.
     * @param  int|null  $max  Stop after this many records, or null for "until Close says stop".
     */
    public function __construct(
        private readonly Transport $transport,
        private readonly string $path,
        private readonly array $query = [],
        private readonly int $pageSize = self::DEFAULT_PAGE_SIZE,
        private readonly ?int $max = null,
    ) {
        if ($pageSize < 1) {
            throw new InvalidArgumentException('Page size must be at least 1.');
        }

        if ($max !== null && $max < 1) {
            throw new InvalidArgumentException('Maximum records must be at least 1, or null for no limit.');
        }

        if (isset($this->query['_skip']) || isset($this->query['_limit'])) {
            throw new InvalidArgumentException(
                'Pass the page size to the paginator rather than as _skip or _limit in the query.',
            );
        }
    }

    /**
     * Each record in turn, fetching pages as it goes.
     *
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
     * Each page as a Response, for a caller who wants the envelope — the rate
     * limit state, or `has_more` — rather than the records.
     *
     * @return Generator<int, Response>
     */
    public function pages(): Generator
    {
        $skip = 0;
        $fetched = 0;

        while (true) {
            $limit = $this->pageSize;

            // Do not ask for more than the caller will keep. Close bills a
            // large page the same as a small one against the rate limit, but
            // there is no reason to make it assemble records that will be
            // discarded.
            if ($this->max !== null) {
                $limit = min($limit, $this->max - $skip);

                if ($limit < 1) {
                    return;
                }
            }

            $page = $this->fetch($skip, $limit, $fetched);
            $fetched++;

            yield $page;

            if (! $page->hasMore()) {
                return;
            }

            $count = count($page->data());

            // has_more true with an empty page would loop forever. Close should
            // never do this; a client that would hang if it did is still a bug.
            if ($count === 0) {
                return;
            }

            $skip += $count;
        }
    }

    /**
     * The first page only, for a caller who wants one page and the count.
     */
    public function first(): Response
    {
        foreach ($this->pages() as $page) {
            return $page;
        }

        throw new \LogicException('Unreachable: pages() always yields at least once.');
    }

    /**
     * Every record, as an array.
     *
     * Only for a collection known to be small — it holds the whole result in
     * memory, and without `max` it is unbounded. Iterate the paginator instead
     * when the size is not known.
     *
     * @return list<mixed>
     */
    public function all(): array
    {
        return iterator_to_array($this, preserve_keys: false);
    }

    private function fetch(int $skip, int $limit, int $fetched): Response
    {
        $query = $this->query + ['_skip' => $skip, '_limit' => $limit];

        try {
            return $this->transport->get($this->path, $query);
        } catch (BadRequestException $e) {
            if ($fetched === 0) {
                throw $e;
            }

            throw new DeepPaginationException(
                sprintf(
                    'Close rejected page %d of %s at _skip=%d. This is likely the per-resource '
                    .'_skip cap, which varies by resource and is not documented anywhere but the '
                    .'error itself - so read the message below, which usually names the number. '
                    .'The answer to a long collection is to chunk the query by date_created, or '
                    .'to use the Export API. The original error was: %s',
                    $fetched + 1,
                    $this->path,
                    $skip,
                    $e->getMessage(),
                ),
                $e->status(),
                $e->body(),
                $e->rawBody(),
                $e->method(),
                $e->uri(),
                $skip,
                $fetched,
                $e->rateLimit(),
            );
        }
    }
}
