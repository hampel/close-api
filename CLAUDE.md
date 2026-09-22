# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`hampel/close-api` — a PHP client for the [Close CRM REST API](https://developer.close.com/),
published on Packagist. Library only: no framework, no service provider, no console entry point.
PSR-4 `Hampel\CloseApi\` → `src/`, `Hampel\CloseApi\Tests\` → `tests/`.

## Commands

```bash
composer install
composer check                                         # lint, analyse, test
composer test                                          # phpunit
composer analyse                                       # phpstan, level 10, PHP 8.3-8.5 in one pass
composer format                                        # pint
vendor/bin/phpunit tests/Http/TransportTest.php        # one file
vendor/bin/phpunit --filter it_adds_the_trailing_slash # one test
```

## The suite cannot tell you Close still agrees

A green suite means the requests are built as intended, not that the API accepts them: the tests
mock the HTTP client, so they can only confirm what this package already believes.

`harness/` is where that gets checked, against a real organization. The reads have been run; no
write exercise exists yet. The closing section of `DESIGN.md` lists what is still unverified.

The first harness run found a defect the whole suite agreed with: `Response::isList()` required
`has_more` or `cursor`, and two endpoints answer with `data` alone, so `data()` handed back the
envelope and `count()` returned the number of keys. Nothing raised. Expect more of that shape —
it is the class of bug no offline check can reach.

## Read DESIGN.md first

`DESIGN.md` records the API facts this design is a response to — the rate limit model, the two
pagination styles and their caps, what the OpenAPI spec does and does not contain — plus the
decisions taken and the ones deliberately declined. Several shapes in `src/` look arbitrary until
you know which Close behaviour forced them. Read it before proposing a structural change.

## Architecture

    Client  ──►  Resource\*  ──►  Transport  ──►  PSR-18

`Http\Transport::request()` is the **only** place a request is issued. Everything true of every
call lives there: base URI, auth, JSON, the `RateLimit` header, retries, error mapping, logging.
Adding behaviour that applies to all requests means editing that one method — there is no
middleware stack and no second path.

Resource classes build paths and payloads and delegate. They never touch HTTP, which is what makes
them cheap to test exhaustively.

`Http\Response` is the single response type. There are no per-endpoint response classes and adding
one is a design decision, not a refactor — see DESIGN.md for why.

## Conventions worth knowing before you edit

- **Paths get their trailing slash added by the transport.** Every Close path ends in one and a
  request without it does not arrive. Do not add defensive slashes at call sites.
- **Query parameters are arrays, never strings in the path.** `Transport::url()` throws on a `?`.
  Lists are comma-joined by `Http\Query`, not repeated — `http_build_query` would produce
  `id__in[0]=` which Close silently ignores, returning the *unfiltered* collection.
- **Response keys are literal.** No dot notation, ever: Close names custom fields
  `custom.cf_xxxxxxxx`.
- **The PSR-18 client is always passed, never discovered.** PSR-17 factories are optional and
  found by `Support\Psr17Discovery`, by class name. Do not add `php-http/discovery` to `require`:
  it is a Composer plugin a consumer may refuse. It is in the dev tree only because
  `php-http/mock-client` needs it, and `allow-plugins` declines it.
- **`Authentication::describe()` must never return the secret.** It is written to the log on every
  request.
- **The error body shape is unverified.** Close documents neither its keys nor its structure.
  `Transport::message()` searches plausible keys and falls back to the status line; nothing asserts
  a shape. DESIGN.md's closing section lists this and the other questions a live call would settle;
  an empty organization raises none of them.

## The harness

`harness/` holds `hampel/rig` exercises that drive the real API. They are not tests: they assert
nothing and return no verdict, they are read by a person, and they reach outside this machine.

```bash
vendor/bin/rig                 # list exercises
vendor/bin/rig inventory       # read-only: whose key is this, and is the organization empty
```

`CLOSE_API_KEY` comes from `.env` at the package root (see `.env.example`). Rig withholds that file
when `CLAUDECODE` is set, so an exercise run by an agent fails for want of a credential by design —
`--agent-may-load-env` overrides it, and is only for an agent that has been asked to do the real
thing.

Every exercise so far is read-only and says so on its first line. A write exercise must guard
itself twice, name its throwaway records `ZZ DELETE ME ...` so a failed cleanup is obvious in the
Close UI, and clean up in a `finally` — with any `exit()` outside that block, because PHP does not
run `finally` on `exit()`.

## Testing

PHPUnit 12 with `failOnRisky`, `failOnWarning`, `failOnDeprecation` and `failOnNotice` on.

Tests assert against the **real PSR-7 request** the transport produced, using
`php-http/mock-client`. Do not introduce a mock of this package's own interfaces: such a mock
encodes the same assumptions as the code, so the two agree with each other regardless of what Close
actually does, and the suite goes green on a request no server would accept.

`tests/TestCase.php` wires the mock client and gives you `queue()`, `request()` and `requests()`.
`Tests\Double\RecordingSleeper` makes retry tests instant; `Tests\Double\NeverRetry` is the default
policy so an unrelated test never sends two requests.

## Version support

`php >=8.3`, with no upper bound. CI runs 8.3, 8.4 and 8.5; PHPStan covers the whole range in one
pass, so the declared constraint and the tested one stay the same thing.

The floor tracks upstream security support: a version is supported while PHP still supports it, and
dropped when they drop it. Raising it is a major version bump and belongs in the CHANGELOG as one
line. Nobody loses anything when it moves — Composer resolves an older consumer to the last release
that claimed them, permanently.

`psr/log` is claimed at `^1.0|^2.0|^3.0` deliberately: XenForo 2.3 ships psr/log 1.1.4 and its
autoloader wins over an add-on's private vendor tree. The lowest-dependency CI job keeps that claim
honest.

## Release

Update `CHANGELOG.md` (newest first, `X.Y.Z (YYYY-MM-DD)`, breaking changes called out
explicitly) and commit. Tagging and publishing are the maintainer's.
