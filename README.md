hampel/close-api
================

[![Tests](https://github.com/hampel/close-api/actions/workflows/tests.yml/badge.svg)](https://github.com/hampel/close-api/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/hampel/close-api.svg?style=flat-square)](https://packagist.org/packages/hampel/close-api)
[![Total Downloads](https://img.shields.io/packagist/dt/hampel/close-api.svg?style=flat-square)](https://packagist.org/packages/hampel/close-api)
[![Open Issues](https://img.shields.io/github/issues-raw/hampel/close-api.svg?style=flat-square)](https://github.com/hampel/close-api/issues)
[![License](https://img.shields.io/packagist/l/hampel/close-api.svg?style=flat-square)](https://packagist.org/packages/hampel/close-api)

A PHP client for the [Close CRM REST API](https://developer.close.com/).

By [Simon Hampel](mailto:simon@hampelgroup.com)

Requires PHP 8.3 or later.

Installation
------------

    composer require hampel/close-api

The package talks to any [PSR-18](https://www.php-fig.org/psr/psr-18/) HTTP
client rather than bundling one. If you have no preference, install Guzzle and
it will be found automatically:

    composer require guzzlehttp/guzzle

Usage
-----

```php
use Hampel\CloseApi\Close;

$close = Close::withApiKey($apiKey);

$lead = $close->leads()->get('lead_abc123');

echo $lead['name'];
echo $lead['custom.cf_xyz'];        // custom fields are literal keys
```

Every call returns a `Response`: array access, iteration and `count()` over the
decoded body, plus the status and whatever rate limit state came back with it.
There are no per-endpoint response classes — Close's own spec leaves more than
half its responses untyped, and a class built from a single example is a guess
wearing a contract's clothing.

```php
$leads = $close->leads()->list(['_limit' => 50, '_fields' => ['id', 'name']]);

foreach ($leads->data() as $lead) {
    // ...
}

$close->leads()->create(['name' => 'Wayne Enterprises', 'status_id' => 'stat_x']);
$close->leads()->update('lead_abc123', ['description' => 'Updated']);
$close->contacts()->list(['lead_id' => 'lead_abc123']);
$close->notes()->create(['lead_id' => 'lead_abc123', 'note' => 'Called back']);
$close->tasks()->create(['_type' => 'lead', 'lead_id' => 'lead_abc123', 'text' => 'Follow up']);
```

To wire the transport yourself rather than letting discovery find a client:

```php
use Hampel\CloseApi\Auth\ApiKey;
use Hampel\CloseApi\Http\Transport;

$close = new Close(new Transport(
    auth: new ApiKey($apiKey),
    httpClient: $httpClient,        // any PSR-18 client
    requestFactory: $factory,       // any PSR-17 factory
    streamFactory: $factory,
));
```

### Endpoints that are not wrapped

Close publishes 302 operations and most of them are its own UI's features. The
resources here cover what applications actually use; everything else is one call
away, and that is a supported route rather than a workaround:

```php
$close->transport()->get('playbook/');
$close->transport()->post('webhook/', ['url' => '...']);
```

The [endpoint inventory](https://github.com/hampel/close-api/blob/master/spec/ENDPOINTS.md)
lists the whole API.

### Pagination

Close paginates two different ways and caps both, so neither paginator offers a
plain "fetch everything".

```php
foreach ($close->leads()->paginate(['status_id' => 'stat_x']) as $lead) {
    // pages fetched as needed
}
```

Offset pagination has a maximum `_limit` **and** a maximum `_skip`, per resource,
neither of which Close publishes. Cross one and a later page returns a bare 400;
this package turns that into a `DeepPaginationException` saying how far the walk
got and what to do instead — chunk the query by `date_created`, or use the Export
API.

Searching uses cursors:

```php
$results = $close->search()->paginate([
    'type' => 'and',
    'queries' => [
        ['type' => 'object_type', 'object_type' => 'contact'],
        // ...
    ],
]);
```

Two constraints there are documented and both are traps. A query returns at most
**10,000 objects** — reaching that raises `PaginationLimitException` rather than
handing back a truncated answer that looks complete. And **cursors expire after
30 seconds**, which means the budget is spent between page fetches: a loop doing
real work per record succeeds on a small result set and fails on a large one.
Buffer each page before processing it.

Errors
------

Every exception implements `Hampel\CloseApi\Exception\CloseApiException`, so one
`catch` covers the package. Below that, each status Close documents has its own
class, so you can catch the case you can do something about:

```php
use Hampel\CloseApi\Exception\NotFoundException;
use Hampel\CloseApi\Exception\RateLimitException;
use Hampel\CloseApi\Exception\CloseApiException;

try {
    $lead = $close->leads()->get($id);
} catch (NotFoundException) {
    return null;                          // an ordinary answer, not a failure
} catch (RateLimitException $e) {
    $retryIn = $e->waitSeconds();
} catch (CloseApiException $e) {
    // anything else this package can throw
}
```

`ResponseException` carries the status, the decoded body, the raw body and the
request that caused it. `TransportException` means the request never reached
Close at all — there is no status and no body to inspect.

Rate limits and retries
-----------------------

Close enforces rate limits per endpoint group, per API key and per organization,
and publishes none of those groupings. This package does not pretend to model
them. It reads the `RateLimit` header off each response and exposes it, and when
a 429 arrives it waits exactly as long as Close asked and tries again.

```php
$close->transport()->lastRateLimit()?->remaining;

// or, per response
$leads = $close->leads()->list();
$leads->rateLimit?->remaining;
```

Retries are the default and are deliberately narrow: a 429 is always retried,
because a rate limit is applied before the request is processed and nothing
happened. A 5xx or a connection failure is retried only for idempotent methods —
a POST that failed this way may already have been applied, and repeating it is
how duplicate records get created.

Pass your own policy to change any of that:

```php
Close::withApiKey($apiKey, retryPolicy: new DefaultRetryPolicy(
    maxAttempts: 5,
    baseDelay: 1.0,
    maxDelay: 30.0,
));
```

`maxDelay` is a ceiling on any single wait rather than a target. If Close asks
for longer than it, the request fails with the reason instead of blocking.

Logging
-------

Pass any PSR-3 logger. Requests and responses are logged at `debug`, retries at
`warning`, and unreachable-host failures at `error`. Credentials are never
logged — an API key is described by its prefix and length only.

Note that `debug` will include request URIs and payload sizes, and a URI can
carry a search term. Choose the level accordingly.

Status
------

Pre-1.0, and honestly so: the whole package is verified against Close's OpenAPI
spec, its documentation and a mock PSR-18 client, and **not once against the
live API**. The suite being green means the requests are built the way this
package intends. It does not mean Close agrees.

The places where this package currently infers rather than knows — the error
body's shape most of all — are listed at the end of the
[design notes](https://github.com/hampel/close-api/blob/master/DESIGN.md).

License
-------

MIT — see [LICENSE.md](LICENSE.md).
