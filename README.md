hampel/close-api
================

A PHP client for the [Close CRM REST API](https://developer.close.com/).

By [Simon Hampel](mailto:simon@hampelgroup.com)

Replaces `hampel/close`, which is abandoned. This is a new package rather than a
new major version of that one: it shares no code, no namespace and no API with
it, so a version number implying a lineage would be a fiction.

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
use Hampel\CloseApi\Auth\ApiKey;
use Hampel\CloseApi\Http\Transport;

$transport = new Transport(
    auth: new ApiKey($apiKey),
    httpClient: $httpClient,        // any PSR-18 client
    requestFactory: $factory,       // any PSR-17 factory
    streamFactory: $factory,
);

$lead = $transport->get('lead/lead_abc123/');

echo $lead['name'];
echo $lead['custom.cf_xyz'];        // custom fields are literal keys
```

Every call returns a `Response`: array access, iteration and `count()` over the
decoded body, plus the status and whatever rate limit state came back with it.

```php
$leads = $transport->get('lead/', ['_limit' => 50, '_fields' => ['id', 'name']]);

foreach ($leads->data() as $lead) {
    // ...
}

if ($leads->hasMore()) {
    // ...
}
```

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
    $lead = $transport->get("lead/{$id}/");
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
$transport->lastRateLimit()?->remaining;
```

Retries are the default and are deliberately narrow: a 429 is always retried,
because a rate limit is applied before the request is processed and nothing
happened. A 5xx or a connection failure is retried only for idempotent methods —
a POST that failed this way may already have been applied, and repeating it is
how duplicate records get created.

Pass your own policy to change any of that:

```php
new Transport(
    // ...
    retryPolicy: new DefaultRetryPolicy(maxAttempts: 5, baseDelay: 1.0, maxDelay: 30.0),
);
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

License
-------

MIT — see [LICENSE.md](LICENSE.md).
