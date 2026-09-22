<?php

declare(strict_types=1);

/**
 * Exercise: CREATES AND DELETES REAL RECORDS in the live Close organization.
 *
 * Point it only at an organization whose data is expendable. Run
 * `vendor/bin/rig inventory` first: it reports which organization the key
 * belongs to and whether anything is already in it.
 *
 * Guarded twice, and neither switch can be typed by habit:
 *
 *   CLOSE_ALLOW_WRITES=yes       the ordinary opt-in; may live in .env
 *   CLOSE_AGENT_MAY_WRITE=yes    an agent, asked to write this once; never in .env
 *
 * Both must be exactly "yes". Everything this creates is named
 * "ZZ DELETE ME ..." so a failed cleanup is obvious in the Close UI, and every
 * record is deleted in a finally block. Deleting a lead takes its contacts,
 * notes, tasks and opportunities with it.
 *
 * Written to settle the questions at the end of DESIGN.md that a GET cannot
 * reach:
 *
 *   1. What shape is an error body? Documented nowhere. Transport::message()
 *      and ResponseException::fieldErrors() both guess. Probed here for 400,
 *      401 and 404 separately, because there is no reason they agree.
 *   2. Where do the _limit and _skip caps fall? Unpublished, and the 400 that
 *      reports one is what DeepPaginationException has to explain.
 *   3. Do the required fields match what the spec says? _type on a task,
 *      status on an email, lead_id on a contact.
 *   4. Is a filter honoured, or silently ignored? The dangerous answer is a
 *      200 carrying every record in the organization. Two leads are created so
 *      that a filtered count and an unfiltered one can actually differ.
 *
 * Needs CLOSE_API_KEY in .env at the package root. The key is never printed.
 *
 * @var Hampel\Rig\Io $io
 */

use GuzzleHttp\Client as Guzzle;
use Hampel\CloseApi\Close;
use Hampel\CloseApi\Exception\CloseApiException;
use Hampel\CloseApi\Exception\ResponseException;
use Hampel\CloseApi\Http\Response;

$key = getenv('CLOSE_API_KEY');

if (! is_string($key) || trim($key) === '') {
    $io->error('CLOSE_API_KEY is not set.');
    $io->line('  Rig withholds .env in an agent session by design. Ask rather than working around it.');
    exit(1);
}

// Layer 2: the ordinary opt-in. "yes" rather than "1" so it cannot be set by
// habit alongside some other switch.
if (getenv('CLOSE_ALLOW_WRITES') !== 'yes') {
    $io->line('mode: refused - nothing was created');
    $io->line();
    $io->warn('This exercise writes to a live CRM.');
    $io->line('  Re-run with:  CLOSE_ALLOW_WRITES=yes vendor/bin/rig writes');
    exit(0);
}

// Layer 3: CLAUDECODE is a fact about who is running the command, which a stale
// .env cannot fake. Absent, this falls back to the opt-in above rather than
// assuming a human.
if (getenv('CLAUDECODE') !== false && getenv('CLOSE_AGENT_MAY_WRITE') !== 'yes') {
    $io->line('mode: refused - agent session, nothing was created');
    $io->line();
    $io->warn('CLOSE_ALLOW_WRITES is ignored under an agent.');
    $io->line('  An agent that has been asked to write for real also needs');
    $io->line('  CLOSE_AGENT_MAY_WRITE=yes, which must never live in .env.');
    exit(0);
}

$io->line('mode: WRITING - real records will be created and deleted');
$io->line();

$close = Close::withKey($key, new Guzzle(['timeout' => 30]));
$stamp = date('Y-m-d H:i:s');
$marker = 'ZZ DELETE ME — hampel/close-api harness '.$stamp;

$leadIds = [];
$stray = [];
$failure = null;
$leaked = [];

/**
 * Run a probe that is expected to fail, and report the error body verbatim.
 *
 * This is the point of the whole exercise: the shape of an error body is
 * documented nowhere, so the only honest way to learn it is to cause one and
 * look. Nothing here asserts a shape.
 *
 * A probe that is ACCEPTED is the case to design for, not the exception. Three
 * of these were accepted on the first live run and each left a real record
 * behind, because an exercise written to expect a 400 does not think of itself
 * as creating anything. $onAccepted is how a probe says what to delete if the
 * API takes it after all; it is handed the response and returns [id, deleter]
 * pairs.
 */
$probeError = static function (string $what, callable $call, ?callable $onAccepted = null) use ($io, &$stray): void {
    $io->line();
    $io->line('  '.$what);

    try {
        $result = $call();
        $io->warn('    no error raised - the request was ACCEPTED');

        if ($onAccepted !== null && $result instanceof Response) {
            foreach ($onAccepted($result) as [$id, $delete]) {
                if ($id !== '') {
                    $stray[] = [$id, $delete];
                    $io->line('    it created '.$id.' - registered for cleanup');
                }
            }
        }
    } catch (ResponseException $e) {
        $io->values([
            '    status' => $e->status(),
            '    class' => (new ReflectionClass($e))->getShortName(),
            '    body keys' => $e->body() === [] ? '(body was not JSON)' : implode(', ', array_keys($e->body())),
            '    field errors' => $e->fieldErrors() === [] ? '(none found)' : implode(', ', array_keys($e->fieldErrors())),
            '    message' => $e->getMessage(),
        ]);
        $io->line('    raw: '.substr($e->rawBody(), 0, 400));
    } catch (CloseApiException $e) {
        $io->error('    '.$e::class.' - '.$e->getMessage());
    }
};

$deleteLead = static fn (string $id) => $close->leads()->delete($id);

try {
    // ------------------------------------------------------ the happy path

    $io->title('Creating records');

    $first = $close->leads()->create([
        'name' => $marker.' (1)',
        'description' => 'Created by the hampel/close-api harness. Safe to delete.',
        // Nested contacts on create: the spec documents this on the way in
        // while marking the field deprecated on the way out.
        'contacts' => [[
            'name' => 'ZZ Delete Me',
            'emails' => [['email' => 'nobody@example.test', 'type' => 'office']],
        ]],
    ]);

    $leadIds[] = (string) $first['id'];

    $io->values([
        'lead id' => $first['id'],
        'http status' => $first->status,
        'nested contact accepted' => is_array($first['contacts'] ?? null) && $first['contacts'] !== []
            ? 'yes - '.count($first['contacts']).' returned'
            : 'NO - the contacts field was dropped',
        'display_name' => $first['display_name'] ?? '(none)',
    ]);

    // A second lead, so a filtered count and an unfiltered one can differ. With
    // only one lead in the organization both are 1 and the comparison proves
    // nothing.
    $second = $close->leads()->create(['name' => $marker.' (2)']);
    $leadIds[] = (string) $second['id'];

    $io->values(['second lead id' => $second['id']]);

    $lead = $leadIds[0];

    $io->line();
    $io->title('Attaching to the first lead');

    $contact = $close->contacts()->create([
        'lead_id' => $lead,
        'name' => 'ZZ Delete Me (explicit)',
        'emails' => [['email' => 'nobody-2@example.test', 'type' => 'office']],
    ]);

    $note = $close->notes()->create(['lead_id' => $lead, 'note' => 'Harness note. Safe to delete.']);

    $task = $close->tasks()->create([
        '_type' => 'lead',
        'lead_id' => $lead,
        'text' => 'ZZ Delete Me - harness task',
        'date' => date('Y-m-d'),
    ]);

    // status "draft" records an email without transmitting anything.
    // DO NOT change this to "outbox": that queues the message and Close sends
    // it to a real address.
    $email = $close->emails()->create([
        'lead_id' => $lead,
        'status' => 'draft',
        'subject' => 'ZZ Delete Me - harness draft',
        'to' => ['nobody@example.test'],
        'body_text' => 'Harness draft. Never sent.',
    ]);

    $io->values([
        'contact' => $contact['id'],
        'note' => $note['id'],
        'task' => $task['id'],
        'email (draft)' => $email['id'],
    ]);

    $io->line();
    $io->title('Reading back and updating');

    $fetched = $close->leads()->get($lead);
    $updated = $close->leads()->update($lead, ['description' => 'Updated by the harness.']);

    $io->values([
        'round trip name' => $fetched['name'],
        'update applied' => $updated['description'] === 'Updated by the harness.' ? 'yes' : 'NO',
        'contacts on the lead' => is_array($fetched['contacts'] ?? null) ? count($fetched['contacts']) : 0,
    ]);

    // ------------------------------------------- is the filter honoured?

    $io->line();
    $io->title('Is a filter honoured, or silently ignored?');

    // Both counts are fetched here rather than reusing a number printed
    // earlier: when they agree, that agreement is itself the check that both
    // requests looked at the same collection.
    $all = $close->activities()->list(['_limit' => 100]);
    $mine = $close->activities()->list(['_limit' => 100, 'lead_id' => $lead]);

    $io->values([
        'activities, unfiltered' => count($all->data()),
        'activities, lead_id filter' => count($mine->data()),
    ]);

    if (count($all->data()) === count($mine->data())) {
        $io->warn('  Equal counts. Either the filter is ignored, or the second lead has no');
        $io->warn('  activities of its own - inconclusive rather than a finding.');
    } else {
        $io->success('  The filter changed the result, so it is being honoured.');
    }

    $leadsFiltered = $close->leads()->list(['_limit' => 100, 'query' => 'name:"'.$marker.' (2)"']);
    $leadsAll = $close->leads()->list(['_limit' => 100]);

    $io->values([
        'leads, unfiltered' => count($leadsAll->data()),
        'leads, ?query= filter' => count($leadsFiltered->data()),
    ]);

    // ------------------------------------------------------ error shapes

    $io->line();
    $io->title('What does an error body look like?');

    $probeError('404 - a lead id that does not exist', static fn () => $close->leads()->get('lead_'.str_repeat('z', 22)));

    // If these are accepted, Close has invented a lead to hang the record on.
    // Deleting that lead takes the record with it.
    $orphanLead = static fn (Response $r): array => [[(string) ($r['lead_id'] ?? ''), $deleteLead]];

    $probeError(
        '400 - a contact with no lead_id',
        static fn () => $close->contacts()->create(['name' => 'ZZ no lead']),
        $orphanLead,
    );

    $probeError(
        '400 - an opportunity with no lead_id',
        static fn () => $close->opportunities()->create(['note' => 'ZZ no lead']),
        $orphanLead,
    );

    $probeError(
        '400 - an unknown field on a lead',
        static fn () => $close->leads()->create([
            'name' => $marker.' (rejected)',
            'not_a_real_field_at_all' => 'x',
        ]),
        static fn (Response $r): array => [[(string) ($r['id'] ?? ''), $deleteLead]],
    );

    $probeError('400 - a status_id that is not a status', static fn () => $close->leads()->update($lead, ['status_id' => 'stat_'.str_repeat('z', 22)]));

    $probeError('401 - a key that is not a key', static function (): void {
        $wrong = Close::withKey('api_'.str_repeat('z', 30), new Guzzle(['timeout' => 30]));
        $wrong->users()->me();
    });

    // ------------------------------------------------ the pagination caps

    $io->line();
    $io->title('Where do the pagination caps fall?');

    foreach ([200, 1000] as $limit) {
        $probeError(sprintf('_limit=%d on lead/', $limit), static fn () => $close->leads()->list(['_limit' => $limit]));
    }

    $probeError('_skip=1000000 on lead/', static fn () => $close->leads()->list(['_limit' => 1, '_skip' => 1_000_000]));

    // ------------------------------------------------------------ delete

    $io->line();
    $io->title('Delete, and what a deleted record reads as');

    $goner = array_pop($leadIds);
    $deleted = $close->leads()->delete($goner);

    $io->values([
        'delete status' => $deleted->status,
        'delete body' => $deleted->all() === [] ? '(empty)' : implode(', ', array_keys($deleted->all())),
    ]);

    // A GET straight after a DELETE has been observed returning 200: the read
    // path lags the write. The second DELETE is the authoritative check, and
    // the list index takes longer still - up to about a minute.
    $probeError('GET the lead immediately after deleting it', static fn () => $close->leads()->get($goner));
    $probeError('DELETE it a second time', static fn () => $close->leads()->delete($goner));

    // ------------------------------------------------------- rate limits

    $limit = $close->transport()->lastRateLimit();

    $io->line();
    $io->title('Rate limit, as last reported');

    if ($limit === null) {
        $io->line('  the last response carried no RateLimit header');
    } else {
        $io->values([
            'limit' => $limit->limit,
            'remaining' => $limit->remaining,
            'resets in' => sprintf('%.1fs', $limit->resetSeconds()),
        ]);
    }
} catch (CloseApiException $e) {
    $failure = $e;
    $io->line();
    $io->error('Unexpected failure: '.$e::class);
    $io->error($e->getMessage());
} finally {
    $io->line();
    $io->title('Cleanup');

    $toDelete = array_map(static fn (string $id): array => [$id, $deleteLead], $leadIds);

    foreach ([...$toDelete, ...$stray] as [$id, $delete]) {
        try {
            $delete($id);
            $io->success('  deleted '.$id);
        } catch (CloseApiException $cleanup) {
            $leaked[] = $id;
            $io->error('  ✗ CLEANUP FAILED for '.$id.' - '.$cleanup::class);
            $io->error('    '.$cleanup->getMessage());
        }
    }

    if ($leaked !== []) {
        $io->line();
        $io->error('Remove these by hand - `vendor/bin/rig cleanup` finds the named ones:');

        foreach ($leaked as $id) {
            $io->error('  '.$id);
        }
    }
}

// Outside the try: PHP does not run finally on exit(), so an exit inside it
// would skip cleanup on exactly the runs that got that far.
if ($failure !== null || $leaked !== []) {
    exit(1);
}

$io->line();
$io->success('Done. Everything created was deleted.');

exit(0);
