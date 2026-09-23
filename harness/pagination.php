<?php

declare(strict_types=1);

/**
 * Exercise: CREATES A FEW HUNDRED LEADS, pages and searches them, then deletes them. Live API.
 *
 * The same two switches `writes.php` uses, and for the same reason:
 *
 *   CLOSE_ALLOW_WRITES=yes       the ordinary opt-in; may live in .env
 *   CLOSE_AGENT_MAY_WRITE=yes    an agent, asked to write this once; never in .env
 *
 * Run `vendor/bin/rig inventory` first. This one creates more records than any
 * other exercise here, so point it only at an organization whose data is
 * expendable, and expect it to take several minutes.
 *
 * It exists because an empty organization cannot exercise the most intricate
 * code in the package. `Paginator`, `CursorPaginator` and `Search` were written
 * from prose and verified against mocks built on the same assumptions — the
 * arrangement that has already produced three silent bugs here. Specifically:
 *
 *   1. Does offset pagination actually walk a collection larger than one page?
 *      The loop advances `_skip` by what it received, which is only ever
 *      exercised for real once there is a second page.
 *   2. Is `POST /data/search/` shaped the way the prose says? It is absent from
 *      the OpenAPI spec, so prose is the only description, and it is the only
 *      way to find a lead by anything but its id.
 *   3. Do search cursors really expire after 30 seconds? Every consumer that
 *      does per-record work between pages depends on the answer.
 *   4. Does the `_params` / `x-http-method-override` switch work on a real
 *      endpoint? The transport takes that path silently above 1900 characters
 *      of query string, and no test has ever sent one to Close.
 *
 * Needs CLOSE_API_KEY in .env at the package root. The key is never printed.
 *
 * @var Hampel\Rig\Io $io
 */

use GuzzleHttp\Client as Guzzle;
use Hampel\CloseApi\Close;
use Hampel\CloseApi\Exception\CloseApiException;
use Hampel\CloseApi\Exception\CursorExpiredException;
use Hampel\CloseApi\Pagination\CursorPaginator;
use Psr\Log\AbstractLogger;

/**
 * Enough leads to need more than one page at Close's maximum `_limit` of 200,
 * which is the bound the offset paginator has to cross correctly.
 */
const LEADS = 230;

$key = getenv('CLOSE_API_KEY');

if (! is_string($key) || trim($key) === '') {
    $io->error('CLOSE_API_KEY is not set.');
    exit(1);
}

if (getenv('CLOSE_ALLOW_WRITES') !== 'yes') {
    $io->line('mode: refused - nothing was created');
    $io->line();
    $io->warn('This exercise creates '.LEADS.' leads in a live CRM.');
    $io->line('  Re-run with:  CLOSE_ALLOW_WRITES=yes vendor/bin/rig pagination');
    exit(0);
}

if (getenv('CLAUDECODE') !== false && getenv('CLOSE_AGENT_MAY_WRITE') !== 'yes') {
    $io->line('mode: refused - agent session, nothing was created');
    $io->line();
    $io->warn('CLOSE_ALLOW_WRITES is ignored under an agent; CLOSE_AGENT_MAY_WRITE=yes is also needed.');
    exit(0);
}

$io->line('mode: WRITING - '.LEADS.' leads will be created and deleted');
$io->line();

/**
 * Counts the transport's retry warnings.
 *
 * The transport retries a 429 itself, so a caller never sees one — which means
 * the only way to learn whether this run met a rate limit is to listen to the
 * log. Bulk creation is the likeliest way to meet one without deliberately
 * hammering the API.
 */
$retries = new class () extends AbstractLogger {
    /** @var list<string> */
    public array $warnings = [];

    public function log($level, $message, array $context = []): void
    {
        if ($level === 'warning') {
            $this->warnings[] = (string) $message.' — '.($context['error'] ?? '');
        }
    }
};

$close = Close::withKey($key, new Guzzle(['timeout' => 60]), logger: $retries);

// A token unique to this run, so the search can match exactly these leads and
// nothing a previous run left behind.
$token = 'zzpage'.bin2hex(random_bytes(4));
$marker = 'ZZ DELETE ME — hampel/close-api harness '.date('Y-m-d H:i:s');

$created = [];
$failure = null;
$leaked = [];

try {
    // ------------------------------------------------------------- create

    $io->title('Creating '.LEADS.' leads');

    $started = microtime(true);

    for ($i = 1; $i <= LEADS; $i++) {
        $created[] = (string) $close->leads()->create([
            'name' => sprintf('%s %s %03d', $marker, $token, $i),
        ])['id'];

        if ($i % 50 === 0) {
            $io->line(sprintf('  %d/%d', $i, LEADS));
        }
    }

    $io->values([
        'created' => count($created),
        'elapsed' => sprintf('%.1fs', microtime(true) - $started),
        'per second' => sprintf('%.1f', count($created) / max(0.1, microtime(true) - $started)),
    ]);

    // Close's list index lags its writes - a deleted lead stayed listed for
    // 30-60s on an earlier run - so give the index a chance to catch up before
    // counting anything. A short wait here is the difference between measuring
    // pagination and measuring replication lag.
    $io->line();
    $io->line('  waiting 30s for the list index to catch up');
    sleep(30);

    // -------------------------------------------------- offset pagination

    $io->line();
    $io->title('Offset pagination');

    foreach ([50, 200] as $pageSize) {
        $pages = 0;
        $records = 0;
        $skips = [];

        foreach ($close->leads()->paginate([], $pageSize)->pages() as $page) {
            $pages++;
            $records += count($page->data());
            $skips[] = $records;
        }

        $io->values([
            'page size' => $pageSize,
            'pages fetched' => $pages,
            'records seen' => $records,
            'expected pages' => (int) ceil(LEADS / $pageSize),
        ]);

        if ($records !== LEADS) {
            $io->error(sprintf('  walked %d of %d leads - the loop is losing records', $records, LEADS));
        } elseif ($pages < 2) {
            $io->warn('  only one page: the multi-page path was not exercised');
        } else {
            $io->success('  every record seen exactly once, across more than one page');
        }

        $io->line();
    }

    $bounded = iterator_to_array($close->leads()->paginate([], 50, 75), false);
    $io->values([
        'max: 75 returned' => count($bounded),
        'first() page size' => count($close->leads()->paginate([], 50)->first()->data()),
    ]);

    // ------------------------------------- the _params / override switch

    $io->line();
    $io->title('Long filter: does the _params override work?');

    // 230 ids of ~30 characters is far past the 1900-character threshold, so
    // the transport should silently switch to POST with x-http-method-override.
    $subset = array_slice($created, 0, 100);
    $byId = $close->leads()->list(['id__in' => $subset, '_limit' => 200]);

    $io->values([
        'ids asked for' => count($subset),
        'leads returned' => count($byId->data()),
        'query string length' => strlen(implode(',', $subset)),
    ]);

    if (count($byId->data()) === count($subset)) {
        $io->success('  the override path works and the filter was honoured');
    } else {
        $io->error('  count mismatch - the filter was not honoured as sent');
    }

    // ------------------------------------------------------------ search

    $io->line();
    $io->title('Advanced Filtering - POST /data/search/');

    $query = [
        'type' => 'and',
        'queries' => [
            ['type' => 'object_type', 'object_type' => 'lead'],
            [
                'type' => 'field_condition',
                'field' => ['type' => 'regular_field', 'object_type' => 'lead', 'field_name' => 'name'],
                'condition' => ['type' => 'text', 'mode' => 'full_words', 'value' => $token],
            ],
        ],
    ];

    // Close advises sorting by something stable, or records move between pages
    // as the order shifts and are missed.
    $sort = ['sort' => [[
        'direction' => 'asc',
        'field' => ['object_type' => 'lead', 'type' => 'regular_field', 'field_name' => 'date_created'],
    ]]];

    $firstPage = $close->search()->query($query, $sort + ['_limit' => 50]);

    $io->values([
        'first page records' => count($firstPage->data()),
        'envelope keys' => implode(', ', array_keys($firstPage->all())),
        'cursor' => $firstPage->cursor() === null ? '(null - last page)' : 'present',
        'recognised as a list' => $firstPage->isList() ? 'yes' : 'NO',
    ]);

    $found = 0;
    $searchPages = 0;

    foreach ($close->search()->paginate($query, $sort, 50)->pages() as $page) {
        $searchPages++;
        $found += count($page->data());
    }

    $io->line();
    $io->values([
        'search pages' => $searchPages,
        'records found' => $found,
        'leads created' => LEADS,
    ]);

    if ($found === LEADS) {
        $io->success('  the search found exactly the leads this run created');
    } else {
        $io->warn(sprintf('  found %d of %d - the query or the index disagrees', $found, LEADS));
    }

    // ------------------------------------------------- do cursors expire?

    $io->line();
    $io->title('Do search cursors really expire after 30 seconds?');

    // Driven through CursorPaginator rather than by re-posting a stale cursor by
    // hand. Calling search()->query() directly would prove only that Close
    // rejects the cursor; what needs proving is that the PACKAGE recognises the
    // rejection, since CursorExpiredException is the whole point of the
    // elapsed-time bookkeeping in the paginator.
    $pages = $close->search()->paginate($query, $sort, 10)->pages();
    $pages->rewind();

    if (! $pages->valid() || $pages->current()->cursor() === null) {
        $io->warn('  only one page of results; nothing to expire');
    } else {
        $io->line('  holding between pages for 35s, which is past the documented 30');
        sleep(35);

        try {
            $pages->next();
            $io->warn('  the stale cursor was ACCEPTED - it outlived the documented 30s');
        } catch (CursorExpiredException $e) {
            $io->success('  rejected, and the paginator recognised it as an expired cursor');
            $io->line('    '.$e->getMessage());
            $io->values(['    elapsed as measured' => sprintf('%.1fs', $e->elapsed())]);
        } catch (CloseApiException $e) {
            $io->error('  rejected as '.$e::class.' - the paginator did NOT recognise it');
            $io->line('    '.$e->getMessage());
        }
    }

    $io->values(['CursorPaginator::CURSOR_TTL' => CursorPaginator::CURSOR_TTL]);

    // ------------------------------------------------------ rate limiting

    $io->line();
    $io->title('Rate limiting, over '.(count($created) + 10).'+ requests');

    // Sampled across several endpoints rather than read once: no response in any
    // run so far has carried the header, and one observation cannot tell "this
    // endpoint omits it" from "Close has stopped sending it".
    $seen = [];

    foreach (['me/', 'lead/', 'status/lead/', 'user/'] as $path) {
        $close->transport()->get($path, ['_limit' => 1]);
        $seen[$path] = $close->transport()->lastRateLimit() !== null ? 'present' : 'absent';
    }

    $io->values($seen);
    $io->line();

    $limit = $close->transport()->lastRateLimit();

    $io->values([
        'retries logged' => count($retries->warnings),
        'limit' => $limit?->limit ?? '(no header)',
        'remaining' => $limit?->remaining ?? '(no header)',
    ]);

    foreach (array_slice($retries->warnings, 0, 5) as $warning) {
        $io->line('    '.$warning);
    }

    if ($retries->warnings === []) {
        $io->line('  no 429 was met, so the retry path still has not run against a real one');
    }
} catch (CloseApiException $e) {
    $failure = $e;
    $io->line();
    $io->error('Unexpected failure: '.$e::class);
    $io->error($e->getMessage());
} finally {
    $io->line();
    $io->title('Cleanup - deleting '.count($created).' leads');

    $done = 0;

    foreach ($created as $id) {
        try {
            $close->leads()->delete($id);
            $done++;

            if ($done % 50 === 0) {
                $io->line(sprintf('  %d/%d', $done, count($created)));
            }
        } catch (CloseApiException $cleanup) {
            $leaked[] = $id;
        }
    }

    $io->values(['deleted' => $done, 'failed' => count($leaked)]);

    if ($leaked !== []) {
        $io->error('CLEANUP FAILED for '.count($leaked).' leads. They are named "'.$marker.'".');
        $io->error('Remove them with:  CLOSE_ALLOW_WRITES=yes vendor/bin/rig cleanup');
    }
}

// Outside the try: PHP does not run finally on exit().
if ($failure !== null || $leaked !== []) {
    exit(1);
}

$io->line();
$io->success('Done. Everything created was deleted.');

exit(0);
