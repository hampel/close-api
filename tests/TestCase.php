<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Tests;

use Hampel\CloseApi\Auth\ApiKey;
use Hampel\CloseApi\Auth\Authentication;
use Hampel\CloseApi\Http\RetryPolicy;
use Hampel\CloseApi\Http\Transport;
use Hampel\CloseApi\Tests\Double\NeverRetry;
use Hampel\CloseApi\Tests\Double\RecordingSleeper;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Wires a Transport onto a PSR-18 mock client.
 *
 * The assertions in this suite are made against the real PSR-7 request the
 * transport produced — its method, URI, headers and body — rather than against
 * a mock of one of this package's own interfaces. That is the whole point: a
 * mock of our own abstraction encodes the same assumptions the code does, so
 * the two agree with each other whether or not either agrees with Close.
 *
 * This still cannot detect that the remote API changed. Nothing offline can.
 * What it does buy is that a failing test means the request was wrong, rather
 * than that an expectation was restated.
 */
abstract class TestCase extends BaseTestCase
{
    protected MockClient $http;

    protected Psr17Factory $psr17;

    protected RecordingSleeper $sleeper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->psr17 = new Psr17Factory();
        $this->http = new MockClient($this->psr17);
        $this->sleeper = new RecordingSleeper();
    }

    protected function transport(
        ?Authentication $auth = null,
        ?RetryPolicy $retryPolicy = null,
        ?LoggerInterface $logger = null,
    ): Transport {
        return new Transport(
            auth: $auth ?? new ApiKey('api_test'),
            httpClient: $this->http,
            requestFactory: $this->psr17,
            streamFactory: $this->psr17,
            retryPolicy: $retryPolicy ?? new NeverRetry(),
            sleeper: $this->sleeper,
            logger: $logger ?? new \Psr\Log\NullLogger(),
        );
    }

    /**
     * Queue a response for the next request.
     *
     * @param  array<array-key, mixed>|string  $body
     * @param  array<string, string>  $headers
     */
    protected function queue(int $status = 200, array|string $body = [], array $headers = []): void
    {
        $response = $this->psr17->createResponse($status);

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        $payload = is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR);

        $this->http->addResponse($response->withBody($this->psr17->createStream($payload)));
    }

    /**
     * The single request the transport made.
     */
    protected function request(): RequestInterface
    {
        $requests = $this->http->getRequests();

        $this->assertCount(1, $requests, 'Expected exactly one request to have been sent.');

        return $requests[0];
    }

    /**
     * @return list<RequestInterface>
     */
    protected function requests(): array
    {
        return array_values($this->http->getRequests());
    }
}
