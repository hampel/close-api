<?php

declare(strict_types=1);

/**
 * Exercise: find, and optionally DELETE, records the harness left behind. Lists only by default.
 *
 * `writes.php` deletes what it creates, but it cannot delete what it does not
 * know it created: a probe written to expect a 400 leaves a real record behind
 * when the API accepts it instead. That happened on the first live run — three
 * probes were accepted, and each run left an untracked lead, contact and
 * opportunity.
 *
 * So this exists as the sweeper, and as the check that `writes.php` balanced.
 * It matches on the marker name rather than on ids, which is the point of
 * naming throwaway records "ZZ DELETE ME ..." in the first place.
 *
 * Listing is the default and needs no opt-in. Deleting needs both switches, the
 * same two `writes.php` uses:
 *
 *   CLOSE_ALLOW_WRITES=yes       the ordinary opt-in; may live in .env
 *   CLOSE_AGENT_MAY_WRITE=yes    an agent, asked to delete this once; never in .env
 *
 * Needs CLOSE_API_KEY in .env at the package root. The key is never printed.
 *
 * @var Hampel\Rig\Io $io
 */

use GuzzleHttp\Client as Guzzle;
use Hampel\CloseApi\Close;
use Hampel\CloseApi\Exception\CloseApiException;

const MARKER = 'ZZ ';

$key = getenv('CLOSE_API_KEY');

if (! is_string($key) || trim($key) === '') {
    $io->error('CLOSE_API_KEY is not set.');
    exit(1);
}

$deleting = getenv('CLOSE_ALLOW_WRITES') === 'yes'
    && (getenv('CLAUDECODE') === false || getenv('CLOSE_AGENT_MAY_WRITE') === 'yes');

$io->line($deleting
    ? 'mode: DELETING - every marked record below will be removed'
    : 'mode: listing only - nothing will be deleted');
$io->line();

$close = Close::withKey($key, new Guzzle(['timeout' => 30]));

/**
 * Records whose name (or note, for an opportunity) carries the marker.
 *
 * Matching on the marker rather than deleting the whole collection is what
 * makes this safe to run against an organization that also holds real data.
 *
 * @return list<array{string, string}>
 */
$marked = static function (callable $list, string $field) use ($io): array {
    $found = [];

    try {
        foreach ($list()->data() as $record) {
            if (! is_array($record)) {
                continue;
            }

            $label = (string) ($record[$field] ?? '');

            if (str_starts_with($label, MARKER)) {
                $found[] = [(string) ($record['id'] ?? ''), $label];
            }
        }
    } catch (CloseApiException $e) {
        $io->error('  could not list: '.$e::class.' - '.$e->getMessage());
    }

    return $found;
};

/**
 * Delete a marked contact or opportunity, and the lead it sits on when that
 * lead is one Close invented for it.
 *
 * Posting a contact or an opportunity with no lead_id does not fail: Close
 * creates an empty, unnamed lead to hang the record on. Deleting only the
 * record leaves that lead behind, and an unnamed lead matches no marker, so it
 * becomes invisible litter. The name is the safety check — a marked record
 * sitting on a lead somebody named is not an orphan, and only the record goes.
 */
$deleteWithOrphanedLead = static function (string $id, callable $fetch, callable $delete) use ($close): void {
    $leadId = null;

    try {
        $leadId = $fetch($id)['lead_id'] ?? null;
    } catch (CloseApiException) {
        // fall through to deleting the record on its own
    }

    if (is_string($leadId) && $leadId !== '') {
        try {
            $lead = $close->leads()->get($leadId);

            if (($lead['name'] ?? '') === '') {
                $close->leads()->delete($leadId);

                return;
            }
        } catch (CloseApiException) {
            // fall through
        }
    }

    $delete($id);
};

$groups = [
    // Leads first: deleting one takes its contacts, notes, tasks and
    // opportunities with it, so the orphan sweeps below find less to do.
    'leads' => [$marked(static fn () => $close->leads()->list(['_limit' => 200]), 'name'),
        static fn (string $id) => $close->leads()->delete($id)],
    'contacts' => [$marked(static fn () => $close->contacts()->list(['_limit' => 200]), 'name'),
        static fn (string $id) => $deleteWithOrphanedLead(
            $id,
            static fn (string $i) => $close->contacts()->get($i),
            static fn (string $i) => $close->contacts()->delete($i),
        )],
    'opportunities' => [$marked(static fn () => $close->opportunities()->list(['_limit' => 200]), 'note'),
        static fn (string $id) => $deleteWithOrphanedLead(
            $id,
            static fn (string $i) => $close->opportunities()->get($i),
            static fn (string $i) => $close->opportunities()->delete($i),
        )],
];

$total = 0;
$failed = [];

foreach ($groups as $name => [$records, $delete]) {
    $io->title(sprintf('%s (%d marked)', $name, count($records)));

    if ($records === []) {
        $io->line('  none');
        $io->line();

        continue;
    }

    foreach ($records as [$id, $label]) {
        $total++;

        if (! $deleting) {
            $io->line(sprintf('  %s  %s', $id, $label));

            continue;
        }

        try {
            $delete($id);
            $io->success(sprintf('  deleted %s  %s', $id, $label));
        } catch (CloseApiException $e) {
            $failed[] = $id;
            $io->error(sprintf('  ✗ %s - %s', $id, $e->getMessage()));
        }
    }

    $io->line();
}

if ($total === 0) {
    $io->success('Nothing marked. The harness balanced.');
    exit(0);
}

if (! $deleting) {
    $io->warn(sprintf('%d marked record(s) left behind.', $total));
    $io->line('  Remove them with:  CLOSE_ALLOW_WRITES=yes vendor/bin/rig cleanup');
    $io->line();
    // Close's list index lags its deletes by up to a minute, so a record listed
    // straight after another exercise may already be gone. Re-run before
    // treating this as a leak - a deleted lead has shown up here once.
    $io->line('  Run straight after another exercise? Wait a minute and re-run first:');
    $io->line('  the list index lags deletes, so a record shown here may already be gone.');
    exit(1);
}

if ($failed !== []) {
    $io->error('Some deletions failed; remove these by hand: '.implode(', ', $failed));
    exit(1);
}

$io->success(sprintf('Removed %d record(s).', $total));

exit(0);
