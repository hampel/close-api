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

### The HTTP client is passed; the factories are found

`Transport` takes a PSR-18 client and never looks for one. Which client sends a
request is a decision a host application may need to keep — its own proxy
settings, or a policy that every outbound request goes through one stack — so a
library that picks one silently takes that decision away.

The PSR-17 factories are the opposite case: any implementation builds the same
request, so they are optional. `Support\Psr17Discovery` looks for Guzzle's,
Nyholm's and Diactoros' factories **by class name, checked with `instanceof`**.
No symbol is written, so `composer-require-checker` sees nothing undeclared and
the analysis run without dev dependencies has nothing to miss.

`php-http/discovery` does the same job and is deliberately not used. It is a
Composer plugin, and a consumer may refuse to run it —
`"allow-plugins": {"php-http/discovery": false}` is a realistic line in an
application's `composer.json`. It still appears in this package's development
tree, pulled in by `php-http/mock-client`, which is why this package's own
`composer.json` refuses it in exactly that way: the suite runs with the plugin
declined.

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

Detecting drift is a harness's job, not the suite's. `harness/inventory.php` is
the read-only half of that: it reports which organization a key belongs to and
what that organization already holds, which is what has to be known before any
exercise writes anything.

It earned its place on the first run. `Response::isList()` required `has_more`
or `cursor` beside `data`, and `custom_field/custom_object_type/` and
`custom_object_type/` send neither — so `data()` returned the envelope,
`count()` returned the number of keys, and an empty organization reported a
custom field it did not have. The suite passed throughout, because every
fixture in it had been written from the same assumption as the code.


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
and Guzzle 7.8.2, so a consumer passes the Guzzle client already present, the
PSR-17 factories are found beside it, and nothing is added to the vendor tree.


What the live runs settled
--------------------------

Everything above was verified against the spec, the documentation and a mock
PSR-18 client. The exercises in `harness/` have since been run against a real
organization — reads on 23 September 2026, writes the same day — and these are
the answers. They are dated because they describe a remote system that changes
without telling anyone.

### The error body has two shapes, not one

They do not overlap, and the difference matters:

```json
401, 404:  {"error": "Unauthorized"}
400:       {"errors": [], "field-errors": {"_limit": "Input should be less than or equal to 200"}}
```

A validation failure is the commonest error there is, and on that shape every
other key is empty — so a message assembled from `error`, `message`, `detail`
and `errors` found nothing and fell back to the status line, reporting
`Bad Request` and discarding the one sentence that said what was wrong.
`Transport::message()` now reads `field-errors` too. `field-errors` is confirmed
as the key `ResponseException::fieldErrors()` was already guessing at.

### The pagination caps, for leads

`_limit` is **200** — `_limit=200` is accepted and `_limit=1000` is refused —
and `_skip` is **35000**. Both per resource, still unpublished, and both
reported as ordinary field errors rather than anything special. Close's own
message for the skip cap names the number and suggests what to do instead —
more than `DeepPaginationException` can infer — so that exception passes Close's
text through beneath its own, and no longer claims the cap is unpublished when
the error is about to state it.

`Paginator::DEFAULT_PAGE_SIZE` stays at 100, which is Close's own default and
half the ceiling.

### `lead_id` is not required, and that is worse than it failing

`POST /contact/` and `POST /opportunity/` with no `lead_id` do **not** fail.
Close creates an empty, unnamed lead and hangs the record on it. Two probes
written to expect a 400 were accepted, and each run left three real records
behind — a lead nobody named, invisible to any sweep that matches on a name.

Nothing in the package can prevent that and nothing should try: it is the API's
behaviour, and a client that refused the call would be refusing something Close
allows. It is written down here because the failure is silent at every level —
no error, no warning, and a lead that looks like it was always there.

### A delete is not immediately visible

A `GET` of a lead immediately after deleting it returned **200**. The second
`DELETE` of the same lead correctly returned 404, so the delete had happened;
the read path was serving a stale copy. The list index lags further still —
between 30 and 60 seconds in these runs before a deleted lead stopped being
counted.

So a write followed straight away by a read of the same record can disagree with
itself, and any harness that checks its own cleanup has to wait before believing
the answer.

### The rate limit header has never been seen at all

This is the one finding that reads worse the longer you look at it.

Close documents `RateLimit: limit=…, remaining=…, reset=…` precisely, and says
"most API responses" carry it. Across every run here — roughly 500 requests,
including 230 creates and 230 deletes back to back — **no response has carried
it once**. `me/`, `lead/`, `status/lead/` and `user/` were sampled deliberately
on the last run: absent on all four.

So `lastRateLimit()` is null in practice, `RateLimit::fromHeader()` has never
parsed a real header, and the retry-on-429 path has never run against a real
429. None of that is broken — null is handled everywhere, and the parser is
tested against the documented form — but the package is reading for something
this organization's responses do not contain, and one test key is not enough to
say whether that is true of Close generally, of this plan, or of this account.

Worth asking Close rather than inferring.

### Pagination, search and the long-filter override all work

Verified on 23 September 2026 against 230 leads created for the purpose:

- **Offset pagination walks a multi-page collection correctly.** 230 records
  seen exactly once at a page size of 50 (five pages) and again at 200 (two
  pages), so the loop's `_skip` advancement is right at both the ordinary case
  and at Close's maximum `_limit`. `max` and `first()` behave.
- **`POST /data/search/` is shaped the way the prose says.** The query tree from
  the documentation returned exactly the 230 leads, over five cursor pages. Its
  envelope is `{"cursor": …, "data": […]}` — `data` with **no `has_more`**,
  which is the third shape `Response::isList()` had to learn. Had that bug not
  been found a week earlier by an unrelated exercise, every search in this
  package would have returned its envelope instead of its records.
- **Cursors really do expire after 30 seconds.** Holding one for 35.2s between
  pages produced `400 {"field-errors": {"cursor": "Expired cursor"}}`, and
  `CursorPaginator` classified it as `CursorExpiredException` with the elapsed
  time attached. The documented number is exact, not approximate.
- **The `_params` / `x-http-method-override` switch works.** A 4,899-character
  `id__in` filter — far past the 1,900 threshold — was sent as a POST with the
  override header and returned precisely the 100 leads asked for. The filter is
  honoured through that path, not silently dropped.

### Other things the write run confirmed

- Nested `contacts` on `POST /lead/` are accepted and returned, despite the
  field being marked deprecated on the way out.
- An unknown field on a lead create is accepted rather than rejected, and the
  lead is created. Whether Close stores it or drops it was not measured.
- A `lead_id` filter on `activity/` is honoured: filtered and unfiltered counts
  differed against two leads.
- `?query=` on `GET /lead/` is honoured too, which the package this replaced had
  no evidence for.
- `DELETE` answers 200 with an empty body.


Questions still open
--------------------

Each is a place where the code guesses, defensibly, and would be tightened by
one observation.

1. **What does a 429 actually look like, and does Close still send the
   `RateLimit` header?** 460 requests in a few minutes drew neither, and the
   header was absent from every endpoint sampled. The retry path and the header
   parser are both written from documentation alone. Provoking a 429 on purpose
   is not something to do casually against an organization someone else shares,
   so this is a question for Close before it is a question for the harness.
2. **Does `Retry-After` really round `reset` up?** The documentation says so.
   `RateLimitException::waitSeconds()` prefers `reset` on that basis.
3. **Do the endpoint groups behave as described?** Whether two paths share a
   limit is not something a client can discover except by observation.
4. **Where do the caps fall on resources other than leads?** Both are
   documented as varying per resource; only `lead/` has been measured.
5. **Does the 10,000-object search cap behave as documented?** Reaching it needs
   ten thousand records, which is a different order of exercise from this one.
