Design notes
============

Why this package exists, what it deliberately does not do, and the facts about
the Close API that the design is a response to. Read this before proposing a
structural change — several of the shapes below look arbitrary until you know
which API behaviour forced them.

The API facts below were checked on 5 September 2026, against the OpenAPI spec
fetched that day and the documentation at `https://developer.close.com/`. They
describe a remote system that changes without notice, so the date is part of
the claim.


What the OpenAPI spec is, and is not
------------------------------------

`https://api.close.com/api/openapi.json` describes 159 paths and 302 operations
across 64 tags. It is an excellent **inventory** and a partial **contract**, and
the difference decides how it may be used.

| | operations |
|---|---|
| response schema | 127 |
| response example only | 118 |
| neither | 57 |
| request body schema | 54 |
| request body example only | 56 |
| no request body | 192 |

`POST /lead/` — the most important create in the API — is example-only. Error
responses carry a description and no schema at all (`{"description": "Bad
request"}`). The spec documents 200/201/204/400/401/403/404 and says nothing
about 402, 405, 415 or **429**, all of which the prose documentation lists.

**The spec is also incomplete.** `POST /api/v1/data/search/` — the Advanced
Filtering API, the endpoint behind every non-trivial lead query — does not
appear in it anywhere.

So the spec is used as a checklist and as a source of parameter names and enum
values. It is **not** used to generate code: a generated client would be
half-typed, would invent the missing half from examples, and would silently omit
the endpoint most consumers reach for first.

One thing it settles cheaply: the custom field path is `/custom_field/{type}/`,
**singular**. The plural is not an endpoint, and it is an easy assumption to
make from the resource name. The types are `activity`, `contact`,
`custom_object_type`, `lead`, `opportunity` and `shared`.


API behaviour the design is a response to
-----------------------------------------

### Rate limits are per endpoint group, and the groups are not published

Limits apply per **endpoint group** — a grouping of URL paths and methods that
Close does not document and can change. They are enforced per API key *and* per
organization, with the organization limit three times the key limit.

The consequence is that a client cannot model the limits. Any local token bucket
is guessing at both the bucket boundaries and the budget, and it is guessing on
behalf of every other process using the same key. So this package does not have
one. It does two honest things instead:

- reads the `RateLimit` header off every response and exposes the parsed values,
  so a caller can throttle itself if it wants to;
- on a 429, waits the interval the response names and retries.

The header is RFC-shaped and replaced an older set of `x-rate-limit-*` headers:

```
RateLimit: limit=100, remaining=50, reset=5
```

`reset` is **seconds as a decimal**, not an integer and not a timestamp. Every
429 is guaranteed to carry both `RateLimit` and `Retry-After`; the documentation
recommends the header's `reset` over `Retry-After`, because `Retry-After` is
rounded up to the next whole second.

Note the documentation still calls this value `rate_reset` in two places, which
was the name of a field in the response body before the body carried it. There
is no `rate_reset` anywhere in a current response. Read `reset` from the header.

Some endpoints have stricter, unpredictable limits and can return a 429 while
`remaining` is non-zero, so `remaining > 0` is not permission to proceed.

### Offset pagination is capped in two directions

List endpoints take `_skip` and `_limit` and return `{"data": [...],
"has_more": bool}`. Both parameters have a maximum that varies per resource and
is not published, and exceeding either returns a 400.

This is why `Paginator` does not offer "iterate everything". A loop that pages
on `has_more` alone works until the collection is large enough to cross the
`_skip` cap and then starts failing. The commoner mistake is worse and quieter:
one call with no limit at all, against a default page size of 100, returning
the first page as though it were the whole set. The documented
way to walk a large collection is to chunk it by `date_created` range, or to use
the Export API. The paginator therefore has a hard stop and reports why it
stopped rather than pretending it finished.

### Two pagination styles, and cursors expire

Advanced Filtering (`POST /data/search/`) and the Event Log (`GET /event/`) use
cursors instead of offsets — `cursor` in the request body and `_cursor` in the
query string respectively. **Advanced Filtering cursors expire after 30
seconds**, so a cursor cannot be handed across a job boundary, stored, or held
while the caller does per-row work. Anything that needs to survive longer has to
re-run the query.

### Long filters go in the body

Filter parameters can exceed the practical 2000-character URL limit, so Close
accepts them as a JSON body under `_params` with an `x-http-method-override:
GET` header on a POST. This is a documented, supported feature rather than a
workaround, and the transport uses it automatically once a query string crosses
the threshold.


Architecture
------------

Three layers, and the boundaries are chosen so that each can be tested without
the one below it.

    Client  ──►  Resource\*  ──►  Transport  ──►  PSR-18
     entry        one class        one send()       any
     point        per resource     choke point      implementation

**Transport** owns everything that is true of every request: the base URI,
authentication, JSON encode and decode, the `RateLimit` header, retries, error
mapping, and logging. There is exactly one method that issues a request, so
there is exactly one place any of that can be got wrong.

This is deliberately not Guzzle middleware. A handler stack would put the same
behaviour one layer further away and lose the thing that makes the logs useful:
`Transport::send()` knows the resource and operation that produced the request,
and middleware only ever sees a PSR-7 object.

**Resources** build paths and payloads and delegate. They never touch HTTP, and
that is what makes them cheap to test exhaustively — the assertion is on the
request that came out, not on a response that had to be faked.

**Response** is one type for every endpoint: `ArrayAccess`, `IteratorAggregate`
and `Countable` over the decoded body, plus the metadata that came off the
envelope — status, rate limit state, `has_more`.

There are no response entity classes. Half the response schemas do not exist in
the spec, so typing those would mean reverse-engineering a structure from a
single example and presenting the guess as a contract — and a type that is
confidently wrong is worse than an array that is honestly untyped. Typed
entities can be layered on later, per resource, without breaking anything.

### Authentication

`Authentication` is an interface with two implementations: `ApiKey`, which is
HTTP Basic with the key as the username and an empty password, and
`BearerToken` for an OAuth 2.0 access token. Close supports both; only the API
key path is exercised today, but the seam costs nothing now and a great deal
later.

### Testing

Tests run against `php-http/mock-client`, a PSR-18 implementation that records
requests and returns queued responses. The assertion is on a real PSR-7 request
object — method, URI, headers, body — not on a mock of this package's own
interface.

That distinction is the whole point. A mock of the package's own client
interface encodes exactly the same assumptions as the code that calls it, so
the two agree with each other whether or not either agrees with Close — and a
green suite then says nothing about the endpoints behind it. Asserting at the
HTTP boundary does not make the suite able to detect that the remote API
changed; nothing offline can do that. What it buys is that a failure means the
request was wrong, rather than that a mock expectation was restated.

Detecting drift is a harness's job, not the suite's, and there is no `harness/`
yet — writing one needs an API key and an organization it is safe to write to.
So every question listed at the end of this document is still open, and
everything here is verified offline only.


Scope
-----

The transport is complete. The resource layer is not, and is not trying to be:
of 302 operations, roughly 60 percent are Close's own UI features — scheduling
links, playbooks, sequences, dialers, send-as, bulk actions, reporting — with no
consumer here. Writing and testing those against assumptions nobody exercises is
how a wrapper accumulates confident, untested, wrong code.

So resources are added when something needs them. `spec/ENDPOINTS.md` is the
generated inventory of everything the API offers, so "what is not covered yet"
is always a mechanical question rather than a guess.


Version support
---------------

PHP 8.3 and up, with no upper bound. CI runs 8.3, 8.4 and 8.5; PHPStan analyses
the whole range in one pass, so nothing is claimed that is not tested.

The floor follows upstream: supported while PHP supports it, dropped when they
drop it, rather than moved when a new feature looks appealing. Raising it is a
major version bump.

`psr/log` is claimed at `^1.0|^2.0|^3.0` for one specific reason: XenForo 2.3
ships `psr/log` 1.1.4, and XenForo's autoloader is registered before an add-on's
private vendor tree, so under XenForo the 1.x classes are the ones that execute
whatever an add-on's own `composer.json` resolved. Narrowing this to `^3.0`
would make the constraint a statement the runtime does not honour. The
lowest-dependency CI job is what keeps the claim true.

The same install is why the PSR-18 choice costs nothing there: XenForo 2.3 also
ships `psr/http-client` 1.0.3, `psr/http-factory` 1.1.0, `psr/http-message` 2.0
and Guzzle 7.8.2, so discovery finds a working client with no added vendor
weight.


Questions only a live call can settle
------------------------------------

Nothing in this package has ever spoken to Close. Everything above was verified
against the spec, the documentation and a mock PSR-18 client; nothing was
verified against the API itself.

That distinction matters more here than it would in most packages, because the
thing being modelled is a remote system that changes without telling anyone. A
green suite means the requests are built the way this package intends. It says
nothing about whether Close still agrees.

These are the specific questions outstanding. Each is a place where the code
currently guesses, defensibly, and would be tightened by one observation. A
harness of exercises driving the real API is the way to settle them, and is
worth writing the day there is a key and an organization safe to write to.

1. **What shape is an error body?** Documented nowhere — not in the prose, not
   in the spec, which gives error responses a description and no schema.
   `Transport::message()` searches `error`, `message`, `detail` and `errors` and
   falls back to the status line; `ResponseException::fieldErrors()` looks for
   `field-errors`. All of that is inference from what has been seen elsewhere.
2. **Where are the `_limit` and `_skip` caps?** Per resource, unpublished. Until
   one is observed, `DeepPaginationException` can only say a later page was
   rejected and that the cap is the likely reason.
3. **What does a 429 actually look like?** The `RateLimit` header is documented
   precisely enough to parse with confidence, but no 429 has been seen, so the
   retry path has never run against a real one.
4. **Does `Retry-After` really round `reset` up?** The documentation says so.
   `RateLimitException::waitSeconds()` prefers `reset` on that basis.
5. **Do the endpoint groups behave as described?** Whether two paths share a
   limit is not something a client can discover except by observation.
6. **Does the `_params` override work as documented on the endpoints this
   package uses it for?** The mechanism is documented generally; it has not been
   exercised against any specific endpoint.
7. **Is `POST /data/search/` still shaped the way the prose says?** It is absent
   from the spec, so the prose is the only description of it, and prose drifts
   more quietly than a schema.

Two of these — 1 and 2 — are the ones where a wrong guess produces a confusing
error rather than a wrong result. The rest would produce a wrong result.
