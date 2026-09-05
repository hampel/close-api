CHANGELOG
=========

Unreleased
----------

First release of `hampel/close-api`. It replaces `hampel/close`, which is
abandoned; it shares no code with it and there is no upgrade path, only a
rewrite of the calling code.

**Added**

* `Http\Transport` — one PSR-18 choke point for every request, owning the base
  URI, authentication, JSON encoding and decoding, the `RateLimit` header,
  retries, error mapping and logging
* `Auth\ApiKey` and `Auth\BearerToken` behind an `Auth\Authentication`
  interface, covering both schemes Close supports
* `Http\Response` — one response type for every endpoint: array access,
  iteration and `count()` over the decoded body, plus the status, the rate limit
  state, `hasMore()` and `cursor()`
* `Http\RateLimit`, parsing Close's `RateLimit: limit=…, remaining=…, reset=…`
  header, including the decimal `reset` the older integer-shaped headers did not
  carry
* `Http\DefaultRetryPolicy` — always retries a 429 for the interval Close names;
  retries 5xx and connection failures on idempotent methods only, with
  exponential backoff and full jitter; retries nothing else
* An exception per documented status, all implementing
  `Exception\CloseApiException`
* Automatic use of Close's `_params` body with an `x-http-method-override: GET`
  header once a GET's query string would exceed the practical URL limit
