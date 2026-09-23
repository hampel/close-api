<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Tests\Http;

use Hampel\CloseApi\Auth\ApiKey;
use Hampel\CloseApi\Exception\AuthenticationException;
use Hampel\CloseApi\Exception\BadRequestException;
use Hampel\CloseApi\Exception\DecodeException;
use Hampel\CloseApi\Exception\ForbiddenException;
use Hampel\CloseApi\Exception\InvalidArgumentException;
use Hampel\CloseApi\Exception\MethodNotAllowedException;
use Hampel\CloseApi\Exception\NotFoundException;
use Hampel\CloseApi\Exception\PaymentRequiredException;
use Hampel\CloseApi\Exception\RateLimitException;
use Hampel\CloseApi\Exception\ResponseException;
use Hampel\CloseApi\Exception\ServerException;
use Hampel\CloseApi\Exception\TransportException;
use Hampel\CloseApi\Exception\UnsupportedMediaTypeException;
use Hampel\CloseApi\Http\Transport;
use Hampel\CloseApi\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class TransportTest extends TestCase
{
    #[Test]
    public function it_sends_a_get_to_the_documented_base_uri(): void
    {
        $this->queue(200, ['id' => 'lead_x']);

        $this->transport()->get('lead/lead_x/');

        $this->assertSame('GET', $this->request()->getMethod());
        $this->assertSame(
            'https://api.close.com/api/v1/lead/lead_x/',
            (string) $this->request()->getUri(),
        );
    }

    /**
     * Every path in the Close API ends in a slash. Close answers 308 to the
     * slashed URL when one is missing rather than failing, so normalising here
     * saves a round trip and removes the dependency on the PSR-18 client
     * following redirects - which PSR-18 does not require it to do.
     */
    #[Test]
    public function it_adds_the_trailing_slash_a_call_site_forgot(): void
    {
        $this->queue();

        $this->transport()->get('custom_field/lead');

        $this->assertSame(
            'https://api.close.com/api/v1/custom_field/lead/',
            (string) $this->request()->getUri(),
        );
    }

    #[Test]
    public function it_tolerates_a_leading_slash_on_the_path(): void
    {
        $this->queue();

        $this->transport()->get('/lead/');

        $this->assertSame('https://api.close.com/api/v1/lead/', (string) $this->request()->getUri());
    }

    #[Test]
    public function it_appends_the_query_string(): void
    {
        $this->queue();

        $this->transport()->get('lead/', ['_limit' => 5, '_fields' => ['id', 'name']]);

        $this->assertSame(
            'https://api.close.com/api/v1/lead/?_limit=5&_fields=id%2Cname',
            (string) $this->request()->getUri(),
        );
    }

    /**
     * A query string in the path would be double-encoded by the query builder
     * and silently produce a different request than the caller wrote.
     */
    #[Test]
    public function it_rejects_a_query_string_hidden_in_the_path(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Pass query parameters as an array');

        $this->transport()->get('lead/?_limit=5');
    }

    #[Test]
    public function it_authenticates_with_http_basic_and_an_empty_password(): void
    {
        $this->queue();

        $this->transport(new ApiKey('api_abc123'))->get('me/');

        $this->assertSame(
            'Basic '.base64_encode('api_abc123:'),
            $this->request()->getHeaderLine('Authorization'),
        );
    }

    #[Test]
    public function it_asks_for_json(): void
    {
        $this->queue();

        $this->transport()->get('me/');

        $this->assertSame('application/json', $this->request()->getHeaderLine('Accept'));
    }

    #[Test]
    public function it_sends_a_json_body_on_a_post(): void
    {
        $this->queue(201, ['id' => 'lead_new']);

        $response = $this->transport()->post('lead/', ['name' => 'Wayne Enterprises']);

        $request = $this->request();
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame('{"name":"Wayne Enterprises"}', (string) $request->getBody());
        $this->assertSame(201, $response->status);
        $this->assertSame('lead_new', $response['id']);
    }

    #[Test]
    public function it_sends_no_body_on_a_get_or_delete(): void
    {
        $this->queue();
        $this->queue(204);

        $transport = $this->transport();
        $transport->get('lead/lead_x/');
        $transport->delete('lead/lead_x/');

        foreach ($this->requests() as $request) {
            $this->assertSame('', (string) $request->getBody());
            $this->assertFalse($request->hasHeader('Content-Type'));
        }
    }

    /**
     * Slashes appear in Close paths and values often enough that escaping them
     * makes a payload unreadable in a log for no benefit.
     */
    #[Test]
    public function it_does_not_escape_slashes_or_unicode_in_a_payload(): void
    {
        $this->queue();

        $this->transport()->post('lead/', ['url' => 'https://example.test/x', 'name' => 'Åke']);

        $this->assertSame(
            '{"url":"https://example.test/x","name":"Åke"}',
            (string) $this->request()->getBody(),
        );
    }

    #[Test]
    public function it_reports_a_payload_it_cannot_encode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('could not be encoded as JSON');

        $this->transport()->post('lead/', ['bad' => "\xB1\x31"]);
    }

    // Long filters move into the body, which is Close's own documented
    // mechanism rather than a workaround.

    #[Test]
    public function it_moves_a_long_query_into_a_params_body_with_a_method_override(): void
    {
        $this->queue(200, ['data' => [], 'has_more' => false]);

        $ids = array_map(static fn (int $i): string => sprintf('lead_%040d', $i), range(1, 50));

        $this->transport()->get('activity/', ['lead_id' => $ids]);

        $request = $this->request();

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('GET', $request->getHeaderLine('x-http-method-override'));
        $this->assertSame('https://api.close.com/api/v1/activity/', (string) $request->getUri());
        $this->assertSame(
            ['_params' => ['lead_id' => implode(',', $ids)]],
            json_decode((string) $request->getBody(), true),
        );
    }

    #[Test]
    public function it_leaves_a_short_query_in_the_url(): void
    {
        $this->queue();

        $this->transport()->get('activity/', ['lead_id' => 'lead_x']);

        $request = $this->request();

        $this->assertSame('GET', $request->getMethod());
        $this->assertFalse($request->hasHeader('x-http-method-override'));
    }

    /**
     * The override header exists for clients that cannot issue arbitrary
     * methods. Using it to disguise a write would hide the write from every
     * proxy between here and Close.
     */
    #[Test]
    public function it_never_disguises_a_write_as_an_overridden_get(): void
    {
        $this->queue();

        $this->transport()->post('lead/', ['note' => str_repeat('x', 4000)]);

        $request = $this->request();

        $this->assertSame('POST', $request->getMethod());
        $this->assertFalse($request->hasHeader('x-http-method-override'));
    }

    // Responses

    #[Test]
    public function it_parses_the_rate_limit_header_off_an_ordinary_response(): void
    {
        $this->queue(200, ['id' => 'x'], ['RateLimit' => 'limit=100, remaining=42, reset=1.5']);

        $transport = $this->transport();
        $response = $transport->get('me/');

        $this->assertNotNull($response->rateLimit);
        $this->assertSame(42, $response->rateLimit->remaining);
        $this->assertSame($response->rateLimit, $transport->lastRateLimit());
    }

    #[Test]
    public function it_survives_a_response_with_no_rate_limit_header(): void
    {
        $this->queue(200, ['id' => 'x']);

        $this->assertNull($this->transport()->get('me/')->rateLimit);
    }

    /**
     * Several deletes answer 204 with nothing at all, which is not a parse
     * failure.
     */
    #[Test]
    public function an_empty_body_decodes_to_an_empty_response(): void
    {
        $this->queue(204, '');

        $response = $this->transport()->delete('lead/lead_x/');

        $this->assertSame(204, $response->status);
        $this->assertSame([], $response->all());
    }

    #[Test]
    public function it_reports_a_success_status_carrying_something_that_is_not_json(): void
    {
        $this->queue(200, '<html><body>Gateway</body></html>');

        try {
            $this->transport()->get('me/');
            $this->fail('Expected a DecodeException.');
        } catch (DecodeException $e) {
            $this->assertSame(200, $e->status());
            $this->assertSame('<html><body>Gateway</body></html>', $e->body());
        }
    }

    #[Test]
    public function it_reports_a_success_status_carrying_a_json_scalar(): void
    {
        $this->queue(200, '"just a string"');

        $this->expectException(DecodeException::class);
        $this->expectExceptionMessage('string where an object was expected');

        $this->transport()->get('me/');
    }

    // Errors

    /**
     * @return list<array{int, class-string<ResponseException>}>
     */
    public static function statuses(): array
    {
        return [
            [400, BadRequestException::class],
            [401, AuthenticationException::class],
            [402, PaymentRequiredException::class],
            [403, ForbiddenException::class],
            [404, NotFoundException::class],
            [405, MethodNotAllowedException::class],
            [415, UnsupportedMediaTypeException::class],
            [429, RateLimitException::class],
            [500, ServerException::class],
            [503, ServerException::class],
        ];
    }

    /**
     * @param  class-string<ResponseException>  $expected
     */
    #[Test]
    #[DataProvider('statuses')]
    public function it_maps_each_documented_status_to_its_exception(int $status, string $expected): void
    {
        $this->queue($status, ['error' => 'nope']);

        $this->expectException($expected);

        $this->transport()->get('lead/lead_x/');
    }

    /**
     * A status Close does not document should still be an error a consumer can
     * catch, not a Response that looks successful.
     */
    #[Test]
    public function an_undocumented_error_status_is_still_a_response_exception(): void
    {
        $this->queue(418, ['error' => 'teapot']);

        $this->expectException(ResponseException::class);

        $this->transport()->get('lead/lead_x/');
    }

    #[Test]
    public function an_error_carries_the_request_that_caused_it(): void
    {
        $this->queue(404, ['error' => 'Lead not found']);

        try {
            $this->transport()->get('lead/lead_missing/');
            $this->fail('Expected a NotFoundException.');
        } catch (NotFoundException $e) {
            $this->assertSame(404, $e->status());
            $this->assertSame('GET', $e->method());
            $this->assertSame('https://api.close.com/api/v1/lead/lead_missing/', $e->uri());
            $this->assertSame(['error' => 'Lead not found'], $e->body());
            $this->assertStringContainsString('Lead not found', $e->getMessage());
        }
    }

    /**
     * Close documents neither the error body's shape nor its keys, so the
     * message is assembled from whichever plausible form is present and falls
     * back to the status line rather than asserting one.
     */
    #[Test]
    public function it_builds_a_message_from_an_errors_list(): void
    {
        $this->queue(400, ['errors' => ['name is required', 'status_id is invalid']]);

        $this->expectExceptionMessage('Close API error 400: name is required; status_id is invalid');

        $this->transport()->post('lead/', []);
    }

    /**
     * The exact body returned by Close on 2026-09-23 for _limit=1000. A
     * validation failure is the commonest error there is, and on this shape
     * "errors" is empty - so reading it alone produced "Bad Request" and threw
     * away the only sentence that said what was wrong.
     */
    #[Test]
    public function a_validation_failure_puts_the_field_errors_in_the_message(): void
    {
        $this->queue(400, [
            'errors' => [],
            'field-errors' => ['_limit' => 'Input should be less than or equal to 200'],
        ]);

        try {
            $this->transport()->get('lead/', ['_limit' => 1000]);
            $this->fail('Expected a BadRequestException.');
        } catch (BadRequestException $e) {
            $this->assertSame(
                'Close API error 400: _limit: Input should be less than or equal to 200',
                $e->getMessage(),
            );
            $this->assertSame(['_limit' => 'Input should be less than or equal to 200'], $e->fieldErrors());
        }
    }

    #[Test]
    public function several_field_errors_are_all_named(): void
    {
        $this->queue(400, [
            'errors' => [],
            'field-errors' => ['status_id' => 'Not a valid choice.', 'name' => 'Required.'],
        ]);

        try {
            $this->transport()->post('lead/', []);
            $this->fail('Expected a BadRequestException.');
        } catch (BadRequestException $e) {
            $this->assertStringContainsString('status_id: Not a valid choice.', $e->getMessage());
            $this->assertStringContainsString('name: Required.', $e->getMessage());
        }
    }

    #[Test]
    public function a_field_error_carrying_a_list_is_joined_rather_than_dropped(): void
    {
        $this->queue(400, ['field-errors' => ['emails' => ['Invalid address.', 'Duplicate.']]]);

        try {
            $this->transport()->post('contact/', []);
            $this->fail('Expected a BadRequestException.');
        } catch (BadRequestException $e) {
            $this->assertStringContainsString('emails: Invalid address. Duplicate.', $e->getMessage());
        }
    }

    #[Test]
    public function top_level_errors_and_field_errors_both_appear(): void
    {
        $this->queue(400, [
            'errors' => ['Something broad went wrong'],
            'field-errors' => ['name' => 'Required.'],
        ]);

        try {
            $this->transport()->post('lead/', []);
            $this->fail('Expected a BadRequestException.');
        } catch (BadRequestException $e) {
            $this->assertStringContainsString('Something broad went wrong', $e->getMessage());
            $this->assertStringContainsString('name: Required.', $e->getMessage());
        }
    }

    /**
     * The other shape observed the same day: 401 and 404 send a plain string
     * under "error" and nothing else.
     */
    #[Test]
    public function the_plain_error_string_shape_is_used_as_the_message(): void
    {
        $this->queue(401, ['error' => 'Unauthorized']);

        try {
            $this->transport()->get('me/');
            $this->fail('Expected an AuthenticationException.');
        } catch (AuthenticationException $e) {
            $this->assertSame('Close API error 401: Unauthorized', $e->getMessage());
        }
    }

    #[Test]
    public function it_falls_back_to_the_status_when_the_body_says_nothing_it_recognises(): void
    {
        $this->queue(400, ['something_unexpected' => true]);

        try {
            $this->transport()->post('lead/', []);
            $this->fail('Expected a BadRequestException.');
        } catch (BadRequestException $e) {
            $this->assertStringStartsWith('Close API error 400', $e->getMessage());
            $this->assertSame(['something_unexpected' => true], $e->body());
        }
    }

    #[Test]
    public function it_keeps_an_error_body_that_is_not_json_at_all(): void
    {
        $this->queue(502, '<html>Bad Gateway</html>');

        try {
            $this->transport()->get('me/');
            $this->fail('Expected a ServerException.');
        } catch (ServerException $e) {
            $this->assertSame([], $e->body());
            $this->assertSame('<html>Bad Gateway</html>', $e->rawBody());
        }
    }

    #[Test]
    public function it_exposes_field_errors_when_the_body_carries_them(): void
    {
        $this->queue(400, ['field-errors' => ['name' => 'This field is required.']]);

        try {
            $this->transport()->post('lead/', []);
            $this->fail('Expected a BadRequestException.');
        } catch (BadRequestException $e) {
            $this->assertSame(['name' => 'This field is required.'], $e->fieldErrors());
        }
    }

    #[Test]
    public function a_rate_limit_error_carries_the_wait_close_asked_for(): void
    {
        $this->queue(429, ['error' => 'too many'], [
            'RateLimit' => 'limit=100, remaining=0, reset=2.5',
            'Retry-After' => '3',
        ]);

        try {
            $this->transport()->get('lead/');
            $this->fail('Expected a RateLimitException.');
        } catch (RateLimitException $e) {
            $this->assertSame(3.0, $e->retryAfter());
            $this->assertSame(2.5, $e->waitSeconds(), 'The header reset is preferred over Retry-After.');
        }
    }

    #[Test]
    public function a_rate_limit_error_without_the_header_falls_back_to_retry_after(): void
    {
        $this->queue(429, [], ['Retry-After' => '4']);

        try {
            $this->transport()->get('lead/');
            $this->fail('Expected a RateLimitException.');
        } catch (RateLimitException $e) {
            $this->assertSame(4.0, $e->waitSeconds());
        }
    }

    #[Test]
    public function a_connection_failure_becomes_a_transport_exception(): void
    {
        $this->http->addException(new \Http\Client\Exception\NetworkException(
            'Connection refused',
            $this->psr17->createRequest('GET', 'https://api.close.com/api/v1/me/'),
        ));

        try {
            $this->transport()->get('me/');
            $this->fail('Expected a TransportException.');
        } catch (TransportException $e) {
            $this->assertStringContainsString('Could not reach the Close API', $e->getMessage());
            $this->assertStringContainsString('Connection refused', $e->getMessage());
            $this->assertInstanceOf(\Http\Client\Exception\NetworkException::class, $e->getPrevious());
        }
    }

    #[Test]
    public function the_base_uri_can_be_pointed_somewhere_else(): void
    {
        $this->queue();

        $transport = new Transport(
            auth: new ApiKey('api_test'),
            httpClient: $this->http,
            requestFactory: $this->psr17,
            streamFactory: $this->psr17,
            baseUri: 'https://close.test/api/v1',
        );

        $transport->get('me/');

        $this->assertSame('https://close.test/api/v1/me/', (string) $this->request()->getUri());
    }
}
