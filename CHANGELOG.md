# CHANGELOG

## 0.2.0 (2026-09-23)

**Added**

* `Close::withKey()` and `Close::with()` take an optional `Sleeper`, so retries
  can be driven by a test double or a framework's sleep helper without building
  a `Transport` by hand. The retry policy was already accepted there; the
  sleeper is the other half of the same mechanism

## 0.1.3 (2026-09-23)

**Changed**

* A 2xx response with an empty body now raises `DecodeException` instead of
  decoding to an empty result. No Close endpoint answers that way — every one
  measured returns JSON, and `DELETE` returns `{}` — so an empty body means the
  response did not come from Close. `204` and `304` are unaffected: HTTP
  forbids a body on those

## 0.1.2 (2026-09-23)

**Fixed**

* `Tasks::create()` rejected a payload with no `_type`, which made it stricter
  than Close: the API accepts the omission and defaults to `lead`. The spec
  models the body as a `oneOf` discriminated on that field, which reads as
  required and is not. Nothing is validated there now

## 0.1.1 (2026-09-23)

**Changed**

* A validation failure now puts Close's own field errors in the exception
  message. Close answers a 400 with `{"errors": [], "field-errors": {…}}`, so a
  message built from `errors` alone fell back to the status line and reported
  `Bad Request` while discarding the sentence that said what was wrong
* `DeepPaginationException` passes Close's own message through rather than
  describing the cap as unpublished — the error names the number

**Fixed**

* `Response::data()` returned the whole envelope instead of the records for
  endpoints that answer with `data` and no pagination marker beside it —
  `custom_field/custom_object_type/` and `custom_object_type/`. `isList()` was
  false for those, so `count()` reported the number of envelope keys and an
  empty collection read as one record. `data()` now returns the records from all
  three envelope shapes Close uses: `data` with `has_more`, `data` with
  `cursor`, and `data` alone

## 0.1.0 (2026-09-13)

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
  header, including the decimal `reset`
* `Http\DefaultRetryPolicy` — always retries a 429 for the interval Close names;
  retries 5xx and connection failures on idempotent methods only, with
  exponential backoff and full jitter; retries nothing else
* An exception per documented status, all implementing
  `Exception\CloseApiException`
* Automatic use of Close's `_params` body with an `x-http-method-override: GET`
  header once a GET's query string would exceed the practical URL limit
* `Close` — the entry point, with `Close::withKey()` taking an API key and a
  PSR-18 client, and `transport()` as a supported route to any endpoint the
  resources do not wrap
* PSR-17 factories are optional: when none are passed, Guzzle's, Nyholm's or
  Diactoros' are found by class name, via `Support\Psr17Discovery`
* Resources for leads, contacts, opportunities, tasks, users, the activity feed,
  notes, emails, calls, SMS, meetings, custom fields, lead and opportunity
  statuses, and the Advanced Filtering API. Each exposes only the operations
  Close actually offers, so `Meetings` has no `create()` and `Users` has no
  `create()`, `update()` or `delete()`
* `Pagination\Paginator` for the offset endpoints, raising
  `DeepPaginationException` — with how far the walk got and what to do instead —
  when a later page is rejected by the unpublished per-resource `_skip` cap
* `Pagination\CursorPaginator` for the Advanced Filtering API, enforcing Close's
  documented 10,000-object cap with `PaginationLimitException` rather than
  returning a truncated result that looks complete, and reporting
  `CursorExpiredException` when more than the documented 30 seconds passed
  between pages

**Known limits**

* Nothing in this release has been run against the live Close API. The
  [design notes](https://github.com/hampel/close-api/blob/master/DESIGN.md)
  list the questions that remain open, the error body's shape chief among them
