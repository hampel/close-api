<?php

declare(strict_types=1);

/**
 * Exercise: read-only inventory of the Close organization the API key belongs to. Reaches the live API.
 *
 * Every request here is a GET. Nothing is created, updated or deleted, so this is
 * safe to run against any organization — including one with real data in it.
 *
 * Written to answer two questions before any write testing happens:
 *
 *   1. Does the key work, and WHICH organization does it belong to? A key made in
 *      the wrong organization authenticates perfectly well and reads someone
 *      else's data, so "it worked" is not the answer — the organization name is.
 *   2. Is that organization empty?
 *
 * The second question needs a distinction the API does not draw for you. Records
 * are things a person created: leads, contacts, opportunities, tasks, activities.
 * Configuration is what every new Close organization is born with: lead and
 * opportunity statuses, a pipeline, and the user who created it. An empty
 * organization has none of the first and some of the second, so a bare count of
 * "objects" would report a fresh organization as non-empty and prove nothing.
 *
 * Webhooks are counted for a different reason: one left over from another
 * integration would fire on every write the next exercise makes, reaching
 * something outside this machine that nobody was thinking about.
 *
 * Needs CLOSE_API_KEY in .env at the package root. The key is never printed.
 *
 * @var Hampel\Rig\Io $io
 */

use GuzzleHttp\Client as Guzzle;
use Hampel\CloseApi\Close;
use Hampel\CloseApi\Exception\CloseApiException;
use Hampel\CloseApi\Resource\CustomFieldType;

$key = getenv('CLOSE_API_KEY');

if (! is_string($key) || trim($key) === '') {
    $io->error('CLOSE_API_KEY is not set.');
    $io->line();
    $io->line('  If you are running this as an agent, rig withholds the package\'s .env by');
    $io->line('  design. That guard is working; ask rather than working around it.');
    exit(1);
}

$io->line('mode: read-only - every request is a GET, nothing is written');
$io->line();

$close = Close::withKey($key, new Guzzle(['timeout' => 30]));

// ---------------------------------------------------------------- identity

$io->title('Whose key is this?');

try {
    $me = $close->users()->me();
} catch (CloseApiException $e) {
    $io->error('Could not read /me/ - '.$e::class);
    $io->error($e->getMessage());
    exit(1);
}

$organizations = is_array($me['organizations'] ?? null) ? $me['organizations'] : [];

$io->values([
    'user' => trim(sprintf('%s %s', $me['first_name'] ?? '', $me['last_name'] ?? '')),
    'user id' => $me['id'] ?? '(none)',
    'email' => $me['email'] ?? '(none)',
    'organizations' => count($organizations),
]);

$io->line();

foreach ($organizations as $organization) {
    if (! is_array($organization)) {
        continue;
    }

    $io->values([
        'organization' => $organization['name'] ?? '(unnamed)',
        'id' => $organization['id'] ?? '(none)',
        'created' => $organization['date_created'] ?? '(unknown)',
    ]);
}

// A key belongs to one organization, so more than one here means the key reaches
// further than a single-organization test key should.
if (count($organizations) > 1) {
    $io->line();
    $io->warn('This key reaches more than one organization.');
}

// ------------------------------------------------------------------ counts

/**
 * Count what a list endpoint returns, as an exact number where it can and as a
 * floor where it cannot.
 *
 * Some endpoints report `total_results` and most do not, so the only answer
 * that holds everywhere is "at least this many". For an organization expected
 * to be empty that is not a limitation: zero is exact, and anything else is the
 * finding.
 *
 * @return array{int, bool}
 */
$countOf = static function (callable $list) use ($io): array {
    try {
        $page = $list();

        return [count($page->data()), (bool) $page->hasMore()];
    } catch (CloseApiException $e) {
        $io->error('  failed: '.$e::class.' - '.$e->getMessage());

        return [-1, false];
    }
};

/**
 * @param  array<string, array{int, bool}>  $counted
 * @return array<string, string>
 */
$describe = static function (array $counted): array {
    $lines = [];

    foreach ($counted as $label => [$count, $hasMore]) {
        $lines[$label] = match (true) {
            $count < 0 => 'could not be read',
            $hasMore => $count.'+ (more pages)',
            default => (string) $count,
        };
    }

    return $lines;
};

$io->line();
$io->title('Records - these should all be zero in a new organization');

$records = [
    'leads' => $countOf(static fn () => $close->leads()->list(['_limit' => 100])),
    'contacts' => $countOf(static fn () => $close->contacts()->list(['_limit' => 100])),
    'opportunities' => $countOf(static fn () => $close->opportunities()->list(['_limit' => 100])),
    'tasks' => $countOf(static fn () => $close->tasks()->list(['_limit' => 100])),
    'activities' => $countOf(static fn () => $close->activities()->list(['_limit' => 100])),
];

$io->values($describe($records));

$io->line();
$io->title('Configuration - a new organization ships with some of this');

$configuration = [
    'users' => $countOf(static fn () => $close->users()->list()),
    'lead statuses' => $countOf(static fn () => $close->leadStatuses()->list()),
    'opportunity statuses' => $countOf(static fn () => $close->opportunityStatuses()->list()),
    'pipelines' => $countOf(static fn () => $close->transport()->get('pipeline/')),
    'webhooks' => $countOf(static fn () => $close->transport()->get('webhook/')),
];

foreach (CustomFieldType::cases() as $type) {
    $configuration['custom fields: '.$type->value] =
        $countOf(static fn () => $close->customFields($type)->list());
}

$configuration['custom object types'] = $countOf(
    static fn () => $close->transport()->get('custom_object_type/')
);

$io->values($describe($configuration));

// ----------------------------------------------------------------- verdict

$io->line();
$io->title('Verdict');

$unreadable = array_keys(array_filter(
    $records + $configuration,
    static fn (array $r): bool => $r[0] < 0,
));

$populated = array_keys(array_filter($records, static fn (array $r): bool => $r[0] > 0));

if ($unreadable !== []) {
    $io->warn('Some endpoints could not be read: '.implode(', ', $unreadable));
    $io->line('  Judge emptiness only from the ones that answered.');
    $io->line();
}

if ($populated === []) {
    $io->success('No records of any kind. Safe for write testing, including deletes.');
} else {
    $io->error('This organization already holds records: '.implode(', ', $populated));
    $io->line('  Do NOT run write exercises against it until you know whose data that is.');
}

// A count on its own cannot say whether something is a Close default or
// somebody's leftover. Name them.
foreach (CustomFieldType::cases() as $type) {
    [$fieldCount] = $configuration['custom fields: '.$type->value];

    if ($fieldCount < 1) {
        continue;
    }

    $io->line();
    $io->line(sprintf('  custom fields on %s:', $type->value));

    foreach ($close->customFields($type)->list()->data() as $field) {
        if (is_array($field)) {
            $io->line(sprintf('    %s  (%s)', $field['name'] ?? '?', $field['id'] ?? '?'));
        }
    }
}

[$webhookCount] = $configuration['webhooks'];

if ($webhookCount > 0) {
    $io->line();
    $io->warn(sprintf(
        '%d webhook subscription(s) configured - writes made here will fire them.',
        $webhookCount,
    ));
}

$limit = $close->transport()->lastRateLimit();

$io->line();

if ($limit === null) {
    // Close documents only that "most" responses carry the header, so an absent
    // one is not a fault. It does mean a caller cannot rely on every response
    // reporting its budget.
    $io->line('  the last response carried no RateLimit header');
} else {
    $io->values([
        'rate limit' => $limit->limit,
        'remaining' => $limit->remaining,
        'window resets in' => sprintf('%.1fs', $limit->resetSeconds()),
    ]);
}

exit($populated === [] ? 0 : 1);
