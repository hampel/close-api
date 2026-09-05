<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Tests\Pagination;

use Hampel\CloseApi\Exception\BadRequestException;
use Hampel\CloseApi\Exception\CursorExpiredException;
use Hampel\CloseApi\Exception\InvalidArgumentException;
use Hampel\CloseApi\Exception\PaginationLimitException;
use Hampel\CloseApi\Pagination\CursorPaginator;
use Hampel\CloseApi\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class CursorPaginatorTest extends TestCase
{
    /**
     * @param  list<string>  $ids
     */
    private function page(array $ids, ?string $cursor): void
    {
        $this->queue(200, [
            'data' => array_map(static fn (string $id): array => ['id' => $id], $ids),
            'cursor' => $cursor,
        ]);
    }

    /**
     * @param  list<float>  $ticks  Values microtime() should return, in order.
     */
    private function paginator(int $pageSize = 2, ?int $max = null, array $ticks = []): CursorPaginator
    {
        $clock = null;

        if ($ticks !== []) {
            $clock = static function () use (&$ticks): float {
                return array_shift($ticks) ?? 0.0;
            };
        }

        return new CursorPaginator(
            $this->transport(),
            'data/search/',
            ['query' => ['type' => 'and']],
            $pageSize,
            $max,
            $clock,
        );
    }

    #[Test]
    public function it_walks_until_the_cursor_comes_back_null(): void
    {
        $this->page(['cont_a', 'cont_b'], 'cursor-1');
        $this->page(['cont_c'], null);

        $ids = array_column($this->paginator()->all(), 'id');

        $this->assertSame(['cont_a', 'cont_b', 'cont_c'], $ids);
        $this->assertCount(2, $this->requests());
    }

    #[Test]
    public function it_sends_no_cursor_on_the_first_page_and_the_previous_one_after(): void
    {
        $this->page(['cont_a'], 'cursor-1');
        $this->page(['cont_b'], null);

        $this->paginator(pageSize: 1)->all();

        $bodies = array_map(
            static fn ($r): mixed => json_decode((string) $r->getBody(), true),
            $this->requests(),
        );

        $this->assertSame(['query' => ['type' => 'and'], '_limit' => 1], $bodies[0]);
        $this->assertSame(['query' => ['type' => 'and'], '_limit' => 1, 'cursor' => 'cursor-1'], $bodies[1]);
    }

    #[Test]
    public function it_posts_to_the_search_endpoint(): void
    {
        $this->page([], null);

        $this->paginator()->all();

        $this->assertSame('POST', $this->request()->getMethod());
        $this->assertSame('https://api.close.com/api/v1/data/search/', (string) $this->request()->getUri());
    }

    #[Test]
    public function it_stops_at_max(): void
    {
        $this->page(['cont_a', 'cont_b'], 'cursor-1');

        $ids = array_column($this->paginator(pageSize: 2, max: 2)->all(), 'id');

        $this->assertSame(['cont_a', 'cont_b'], $ids);
        $this->assertCount(1, $this->requests());
    }

    /**
     * Close documents a hard cap of 10,000 objects per query. Returning the
     * first 10,000 quietly would leave a caller unable to tell a complete
     * answer from a truncated one - the exact bug shape this package exists to
     * stop repeating.
     */
    #[Test]
    public function it_raises_rather_than_truncating_at_the_ten_thousand_cap(): void
    {
        $ids = array_map(static fn (int $i): string => 'cont_'.$i, range(1, CursorPaginator::HARD_LIMIT));

        $this->queue(200, [
            'data' => array_map(static fn (string $id): array => ['id' => $id], $ids),
            'cursor' => 'still-more',
        ]);

        try {
            $this->paginator(pageSize: CursorPaginator::HARD_LIMIT)->all();
            $this->fail('Expected a PaginationLimitException.');
        } catch (PaginationLimitException $e) {
            $this->assertSame(10_000, $e->limit());
            $this->assertSame(10_000, $e->fetched());
            $this->assertStringContainsString('date_created', $e->getMessage());
            $this->assertStringContainsString('Export API', $e->getMessage());
        }
    }

    #[Test]
    public function reaching_the_cap_exactly_on_the_last_page_is_not_an_error(): void
    {
        $ids = array_map(static fn (int $i): string => 'cont_'.$i, range(1, CursorPaginator::HARD_LIMIT));

        $this->queue(200, [
            'data' => array_map(static fn (string $id): array => ['id' => $id], $ids),
            'cursor' => null,
        ]);

        $this->assertCount(10_000, $this->paginator(pageSize: CursorPaginator::HARD_LIMIT)->all());
    }

    /**
     * Cursors expire 30 seconds after Close issues them, so the budget is spent
     * between page fetches - which is why this fails on a large result set and
     * works on a small one.
     */
    #[Test]
    public function a_slow_consumer_gets_told_the_cursor_expired(): void
    {
        $this->page(['cont_a'], 'cursor-1');
        $this->queue(400, ['error' => 'Expired cursor']);

        // now() is called after each successful fetch, then again when the next
        // one fails: issued at t=100, used at t=145.
        $paginator = $this->paginator(pageSize: 1, ticks: [100.0, 145.0]);

        try {
            $paginator->all();
            $this->fail('Expected a CursorExpiredException.');
        } catch (CursorExpiredException $e) {
            $this->assertSame(45.0, $e->elapsed());
            $this->assertStringContainsString('45.0 seconds', $e->getMessage());
            $this->assertStringContainsString('Buffer each page', $e->getMessage());
            $this->assertStringContainsString('Expired cursor', $e->getMessage());
        }
    }

    #[Test]
    public function an_expired_cursor_is_still_a_bad_request(): void
    {
        $this->page(['cont_a'], 'cursor-1');
        $this->queue(400, ['error' => 'Expired cursor']);

        $this->expectException(BadRequestException::class);

        $this->paginator(pageSize: 1, ticks: [100.0, 145.0])->all();
    }

    /**
     * A 400 on the first request cannot be an expired cursor - none had been
     * issued - so it is left as the ordinary bad query it is.
     */
    #[Test]
    public function a_rejected_first_page_is_not_blamed_on_a_cursor(): void
    {
        $this->queue(400, ['error' => 'Malformed query']);

        try {
            $this->paginator()->all();
            $this->fail('Expected a BadRequestException.');
        } catch (BadRequestException $e) {
            $this->assertNotInstanceOf(CursorExpiredException::class, $e);
        }
    }

    #[Test]
    public function a_later_400_unrelated_to_the_cursor_is_left_alone(): void
    {
        $this->page(['cont_a'], 'cursor-1');
        $this->queue(400, ['error' => 'Sort field is not sortable']);

        try {
            $this->paginator(pageSize: 1, ticks: [100.0, 101.0])->all();
            $this->fail('Expected a BadRequestException.');
        } catch (BadRequestException $e) {
            $this->assertNotInstanceOf(CursorExpiredException::class, $e);
            $this->assertStringContainsString('not sortable', $e->getMessage());
        }
    }

    // Construction

    #[Test]
    public function it_refuses_pagination_parameters_in_the_payload(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Pass the page size to the paginator');

        new CursorPaginator($this->transport(), 'data/search/', ['cursor' => 'x']);
    }

    #[Test]
    public function it_publishes_closes_documented_constraints(): void
    {
        $this->assertSame(10_000, CursorPaginator::HARD_LIMIT);
        $this->assertSame(30.0, CursorPaginator::CURSOR_TTL);
    }
}
