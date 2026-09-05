<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Tests\Pagination;

use Hampel\CloseApi\Exception\BadRequestException;
use Hampel\CloseApi\Exception\DeepPaginationException;
use Hampel\CloseApi\Exception\InvalidArgumentException;
use Hampel\CloseApi\Pagination\Paginator;
use Hampel\CloseApi\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class PaginatorTest extends TestCase
{
    /**
     * @param  list<string>  $ids
     * @param  array<string, string>  $headers
     */
    private function page(array $ids, bool $hasMore, array $headers = []): void
    {
        $this->queue(200, [
            'data' => array_map(static fn (string $id): array => ['id' => $id], $ids),
            'has_more' => $hasMore,
        ], $headers);
    }

    private function paginator(int $pageSize = 2, ?int $max = null): Paginator
    {
        return new Paginator($this->transport(), 'lead/', ['status_id' => 'stat_x'], $pageSize, $max);
    }

    #[Test]
    public function it_walks_until_close_says_there_is_no_more(): void
    {
        $this->page(['lead_a', 'lead_b'], true);
        $this->page(['lead_c'], false);

        $ids = array_column($this->paginator()->all(), 'id');

        $this->assertSame(['lead_a', 'lead_b', 'lead_c'], $ids);
        $this->assertCount(2, $this->requests());
    }

    #[Test]
    public function it_advances_skip_by_what_it_actually_received(): void
    {
        $this->page(['lead_a', 'lead_b'], true);
        $this->page(['lead_c'], false);

        $this->paginator()->all();

        $uris = array_map(static fn ($r): string => (string) $r->getUri(), $this->requests());

        $this->assertSame('https://api.close.com/api/v1/lead/?status_id=stat_x&_skip=0&_limit=2', $uris[0]);
        $this->assertSame('https://api.close.com/api/v1/lead/?status_id=stat_x&_skip=2&_limit=2', $uris[1]);
    }

    #[Test]
    public function it_carries_the_filter_onto_every_page(): void
    {
        $this->page(['lead_a'], true);
        $this->page(['lead_b'], false);

        $this->paginator(pageSize: 1)->all();

        foreach ($this->requests() as $request) {
            $this->assertStringContainsString('status_id=stat_x', (string) $request->getUri());
        }
    }

    #[Test]
    public function it_stops_at_max_without_fetching_another_page(): void
    {
        $this->page(['lead_a', 'lead_b'], true);

        $ids = array_column($this->paginator(pageSize: 2, max: 2)->all(), 'id');

        $this->assertSame(['lead_a', 'lead_b'], $ids);
        $this->assertCount(1, $this->requests());
    }

    #[Test]
    public function it_does_not_ask_for_more_records_than_max(): void
    {
        $this->page(['lead_a', 'lead_b', 'lead_c'], true);

        iterator_to_array($this->paginator(pageSize: 100, max: 3));

        $this->assertStringContainsString('_limit=3', (string) $this->request()->getUri());
    }

    #[Test]
    public function it_yields_pages_for_a_caller_who_wants_the_envelope(): void
    {
        $this->page(['lead_a'], true, ['RateLimit' => 'limit=100, remaining=9, reset=1']);
        $this->page(['lead_b'], false);

        $pages = iterator_to_array($this->paginator(pageSize: 1)->pages());

        $this->assertCount(2, $pages);
        $this->assertTrue($pages[0]->hasMore());
        $this->assertSame(9, $pages[0]->rateLimit?->remaining);
        $this->assertFalse($pages[1]->hasMore());
    }

    #[Test]
    public function first_fetches_one_page_and_stops(): void
    {
        $this->page(['lead_a'], true);

        $page = $this->paginator(pageSize: 1)->first();

        $this->assertTrue($page->hasMore());
        $this->assertCount(1, $this->requests());
    }

    /**
     * has_more true with an empty page would loop forever. Close should never
     * do this; a client that would hang if it did is still a bug.
     */
    #[Test]
    public function it_stops_rather_than_looping_on_an_empty_page_that_claims_more(): void
    {
        $this->page([], true);

        $this->assertSame([], $this->paginator()->all());
        $this->assertCount(1, $this->requests());
    }

    /**
     * The heart of this class. Close caps _skip per resource, does not publish
     * the number, and returns an unexplained 400 when it is crossed.
     */
    #[Test]
    public function a_rejected_later_page_becomes_a_deep_pagination_error(): void
    {
        $this->page(['lead_a', 'lead_b'], true);
        $this->queue(400, ['error' => 'Invalid skip']);

        try {
            $this->paginator()->all();
            $this->fail('Expected a DeepPaginationException.');
        } catch (DeepPaginationException $e) {
            $this->assertSame(2, $e->skip());
            $this->assertSame(1, $e->pagesFetched());
            $this->assertStringContainsString('_skip limit', $e->getMessage());
            $this->assertStringContainsString('date_created', $e->getMessage());
            $this->assertStringContainsString('Invalid skip', $e->getMessage(), 'It keeps the original error.');
        }
    }

    /**
     * Existing handling should still catch it.
     */
    #[Test]
    public function a_deep_pagination_error_is_still_a_bad_request(): void
    {
        $this->page(['lead_a'], true);
        $this->queue(400, ['error' => 'nope']);

        $this->expectException(BadRequestException::class);

        $this->paginator(pageSize: 1)->all();
    }

    /**
     * A 400 on the very first page is an ordinary bad query - a malformed
     * filter, an unknown field - and dressing it up as a pagination problem
     * would send the reader in the wrong direction.
     */
    #[Test]
    public function a_rejected_first_page_is_left_as_it_is(): void
    {
        $this->queue(400, ['error' => 'Unknown field']);

        try {
            $this->paginator()->all();
            $this->fail('Expected a BadRequestException.');
        } catch (BadRequestException $e) {
            $this->assertNotInstanceOf(DeepPaginationException::class, $e);
            $this->assertStringContainsString('Unknown field', $e->getMessage());
        }
    }

    // Construction

    #[Test]
    public function it_refuses_pagination_parameters_in_the_filter(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Pass the page size to the paginator');

        new Paginator($this->transport(), 'lead/', ['_skip' => 100]);
    }

    #[Test]
    public function it_refuses_a_page_size_below_one(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Paginator($this->transport(), 'lead/', [], 0);
    }

    #[Test]
    public function it_refuses_a_max_below_one(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Paginator($this->transport(), 'lead/', [], 100, 0);
    }

    #[Test]
    public function the_default_page_size_matches_closes_own(): void
    {
        $this->assertSame(100, Paginator::DEFAULT_PAGE_SIZE);
    }
}
