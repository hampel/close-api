<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Http;

use Hampel\CloseApi\Auth\Authentication;
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
use Hampel\CloseApi\Exception\RuntimeException;
use Hampel\CloseApi\Exception\ServerException;
use Hampel\CloseApi\Exception\TransportException;
use Hampel\CloseApi\Exception\UnsupportedMediaTypeException;
use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The one place a request is issued.
 *
 * Everything true of every call to Close lives here — the base URI,
 * authentication, JSON encoding and decoding, the RateLimit header, retries,
 * error mapping and logging — so that there is exactly one place each of those
 * can be got wrong.
 *
 * This is deliberately not a stack of Guzzle middleware. Middleware would put
 * the same behaviour one layer further away and lose the thing that makes the
 * logs worth having: this method knows the operation that produced the request,
 * where middleware only ever sees a PSR-7 object.
 */
final class Transport
{
    public const string BASE_URI = 'https://api.close.com/api/v1/';

    /**
     * Length at which a GET's query string moves into the request body.
     *
     * Close documents 2000 characters as the practical URL limit and supports
     * sending parameters as a JSON `_params` body with an
     * `x-http-method-override: GET` header instead. The margin below it leaves
     * room for the base URI and the path.
     */
    private const int URL_LIMIT = 1900;

    private ?RateLimit $lastRateLimit = null;

    private string $baseUri;

    public function __construct(
        private readonly Authentication $auth,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly RetryPolicy $retryPolicy = new DefaultRetryPolicy(),
        private readonly Sleeper $sleeper = new SystemSleeper(),
        private readonly LoggerInterface $logger = new NullLogger(),
        string $baseUri = self::BASE_URI,
    ) {
        $this->baseUri = rtrim($baseUri, '/').'/';
    }

    /**
     * The rate limit state Close reported on the most recent response.
     *
     * Null before the first request, and on any response that did not carry the
     * header — Close says only that "most" responses do.
     */
    public function lastRateLimit(): ?RateLimit
    {
        return $this->lastRateLimit;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public function get(string $path, array $query = []): Response
    {
        return $this->request('GET', $path, $query);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $query
     */
    public function post(string $path, array $body = [], array $query = []): Response
    {
        return $this->request('POST', $path, $query, $body);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $query
     */
    public function put(string $path, array $body = [], array $query = []): Response
    {
        return $this->request('PUT', $path, $query, $body);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public function delete(string $path, array $query = []): Response
    {
        return $this->request('DELETE', $path, $query);
    }

    /**
     * Issue a request, retrying per the retry policy.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     *
     * @throws ResponseException  Close answered with an error status.
     * @throws TransportException  The request never reached Close.
     * @throws DecodeException  Close answered with a success status and an unusable body.
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null): Response
    {
        $method = strtoupper($method);
        $attempt = 0;

        while (true) {
            $attempt++;
            $request = $this->buildRequest($method, $path, $query, $body);

            try {
                return $this->send($request, $attempt);
            } catch (ResponseException|TransportException $e) {
                $delay = $this->retryPolicy->retryAfter($attempt, $request, $e);

                if ($delay === null) {
                    throw $e;
                }

                $this->logger->warning('Close API: retrying after failure', [
                    'method' => $method,
                    'path' => $path,
                    'attempt' => $attempt,
                    'delay' => $delay,
                    'error' => $e->getMessage(),
                ]);

                $this->sleeper->sleep($delay);
            }
        }
    }

    /**
     * One attempt: send, record the rate limit, map the status, decode.
     */
    private function send(RequestInterface $request, int $attempt): Response
    {
        $uri = (string) $request->getUri();

        $this->logger->debug('Close API: request', [
            'method' => $request->getMethod(),
            'uri' => $uri,
            'attempt' => $attempt,
            'auth' => $this->auth->describe(),
        ]);

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            $this->logger->error('Close API: request failed', [
                'method' => $request->getMethod(),
                'uri' => $uri,
                'error' => $e->getMessage(),
            ]);

            throw new TransportException(
                sprintf('Could not reach the Close API: %s', $e->getMessage()),
                0,
                $e,
            );
        }

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $rateLimit = $this->readRateLimit($response);
        $this->lastRateLimit = $rateLimit;

        $this->logger->debug('Close API: response', [
            'method' => $request->getMethod(),
            'uri' => $uri,
            'status' => $status,
            'bytes' => strlen($raw),
            'rate_limit_remaining' => $rateLimit?->remaining,
        ]);

        if ($status >= 400) {
            throw $this->error($request, $response, $status, $raw, $rateLimit);
        }

        return new Response($this->decode($raw, $status), $status, $rateLimit);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     */
    private function buildRequest(string $method, string $path, array $query, ?array $body): RequestInterface
    {
        $target = $this->url($path, $query);
        $override = null;

        // A GET whose filters will not fit in a URL goes as a POST carrying
        // them under `_params`, which is Close's own documented mechanism for
        // this rather than a trick. Only GET: the override header exists for
        // clients that cannot issue other methods, and using it to disguise a
        // write would hide the write from every proxy in between.
        if ($method === 'GET' && $query !== [] && strlen($target) > self::URL_LIMIT) {
            $target = $this->url($path, []);
            $body = ['_params' => Query::normalise($query)];
            $override = 'GET';
            $method = 'POST';
        }

        $request = $this->requestFactory->createRequest($method, $target)
            ->withHeader('Accept', 'application/json');

        $request = $this->auth->authenticate($request);

        if ($override !== null) {
            $request = $request->withHeader('x-http-method-override', $override);
        }

        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($this->encode($body)));
        }

        return $request;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function url(string $path, array $query): string
    {
        if (str_contains($path, '?')) {
            throw new InvalidArgumentException(
                sprintf('Pass query parameters as an array, not in the path: "%s".', $path),
            );
        }

        $path = ltrim($path, '/');

        // Every path in the Close API ends in a slash, and a request without
        // one does not reach the endpoint it looks like it should. Adding it
        // here rather than trusting each call site removes a bug class the
        // previous package shipped.
        if ($path !== '' && ! str_ends_with($path, '/')) {
            $path .= '/';
        }

        $url = $this->baseUri.$path;
        $string = Query::build($query);

        return $string === '' ? $url : $url.'?'.$string;
    }

    private function readRateLimit(ResponseInterface $response): ?RateLimit
    {
        $header = $response->getHeaderLine('RateLimit');

        return $header === '' ? null : RateLimit::fromHeader($header);
    }

    /**
     * Map an error status onto the exception a consumer can act on.
     */
    private function error(
        RequestInterface $request,
        ResponseInterface $response,
        int $status,
        string $raw,
        ?RateLimit $rateLimit,
    ): RuntimeException {
        $body = $this->decodeQuietly($raw);
        $method = $request->getMethod();
        $uri = (string) $request->getUri();
        $message = $this->message($status, $body, $response->getReasonPhrase());

        if ($status === 429) {
            $retryAfter = $response->getHeaderLine('Retry-After');

            return new RateLimitException(
                $message,
                $status,
                $body,
                $raw,
                $method,
                $uri,
                $rateLimit,
                is_numeric($retryAfter) ? (float) $retryAfter : null,
            );
        }

        $class = match (true) {
            $status === 400 => BadRequestException::class,
            $status === 401 => AuthenticationException::class,
            $status === 402 => PaymentRequiredException::class,
            $status === 403 => ForbiddenException::class,
            $status === 404 => NotFoundException::class,
            $status === 405 => MethodNotAllowedException::class,
            $status === 415 => UnsupportedMediaTypeException::class,
            $status >= 500 => ServerException::class,
            default => ResponseException::class,
        };

        return new $class($message, $status, $body, $raw, $method, $uri, $rateLimit);
    }

    /**
     * Build a message from whichever of the plausible error keys is present.
     *
     * Close documents neither the error body's shape nor its keys, and its
     * OpenAPI spec gives error responses a description and no schema. So this
     * looks for the forms that have been observed and falls back to the status
     * line — it never assumes it found one.
     *
     * @param  array<array-key, mixed>  $body
     */
    private function message(int $status, array $body, string $reason): string
    {
        foreach (['error', 'message', 'detail'] as $key) {
            if (isset($body[$key]) && is_string($body[$key]) && $body[$key] !== '') {
                return sprintf('Close API error %d: %s', $status, $body[$key]);
            }
        }

        if (isset($body['errors']) && is_array($body['errors']) && $body['errors'] !== []) {
            $strings = array_filter($body['errors'], is_string(...));

            if ($strings !== []) {
                return sprintf('Close API error %d: %s', $status, implode('; ', $strings));
            }
        }

        return $reason === ''
            ? sprintf('Close API error %d', $status)
            : sprintf('Close API error %d: %s', $status, $reason);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decode(string $raw, int $status): array
    {
        // 204 and an empty 200 are both legitimate — several deletes answer
        // with no body at all — so an empty body decodes to an empty response
        // rather than a parse failure.
        if (trim($raw) === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new DecodeException(
                sprintf('Close API returned a %d with a body that is not JSON: %s', $status, $e->getMessage()),
                $raw,
                $status,
                $e,
            );
        }

        if (! is_array($decoded)) {
            throw new DecodeException(
                sprintf('Close API returned a %d with %s where an object was expected.', $status, get_debug_type($decoded)),
                $raw,
                $status,
            );
        }

        return $decoded;
    }

    /**
     * Decode an error body, tolerating anything.
     *
     * An error response is allowed to be an HTML page from a proxy that never
     * reached Close. The raw body is kept on the exception either way, so
     * failing to decode it costs nothing.
     *
     * @return array<array-key, mixed>
     */
    private function decodeQuietly(string $raw): array
    {
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function encode(array $body): string
    {
        try {
            return json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw new InvalidArgumentException(
                sprintf('The request payload could not be encoded as JSON: %s', $e->getMessage()),
                0,
                $e,
            );
        }
    }
}
