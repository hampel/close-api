# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`hampel/close-api` — a PHP client for the [Close CRM REST API](https://developer.close.com/),
published on Packagist. Library only: no framework, no service provider, no console entry point.
PSR-4 `Hampel\CloseApi\` → `src/`, `Hampel\CloseApi\Tests\` → `tests/`.

It replaces `hampel/close`, which is abandoned. No shared code, no upgrade path.

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
- **`Authentication::describe()` must never return the secret.** It is written to the log on every
  request.
- **The error body shape is unverified.** Close documents neither its keys nor its structure.
  `Transport::message()` searches plausible keys and falls back to the status line; nothing asserts
  a shape. If the harness settles it, that is the moment to tighten this.

## Testing

PHPUnit 12 with `failOnRisky`, `failOnWarning`, `failOnDeprecation` and `failOnNotice` on.

Tests assert against the **real PSR-7 request** the transport produced, using
`php-http/mock-client`. Do not introduce a mock of this package's own interfaces: that was the flaw
in the package this replaces, where the mock encoded the same assumptions as the code, so the two
agreed with each other regardless of what Close does.

`tests/TestCase.php` wires the mock client and gives you `queue()`, `request()` and `requests()`.
`Tests\Double\RecordingSleeper` makes retry tests instant; `Tests\Double\NeverRetry` is the default
policy so an unrelated test never sends two requests.

## Version support

`php >=8.3` — Tier A under `/srv/www/version-support.html`. CI runs 8.3, 8.4 and 8.5; PHPStan
covers the whole range in one pass. Widening or narrowing is a policy decision — read that document
first.

`psr/log` is claimed at `^1.0|^2.0|^3.0` deliberately: XenForo 2.3 ships psr/log 1.1.4 and its
autoloader wins over an add-on's private vendor tree. The lowest-dependency CI job keeps that claim
honest.

## Release

Update `CHANGELOG.md` (newest first, `X.Y.Z (YYYY-MM-DD)`, breaking changes called out explicitly)
and commit. Simon does the pushing and tagging.
