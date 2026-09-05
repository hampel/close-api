<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Tests\Http;

use Hampel\CloseApi\Exception\BadRequestException;
use Hampel\CloseApi\Exception\NotFoundException;
use Hampel\CloseApi\Exception\RateLimitException;
use Hampel\CloseApi\Exception\ServerException;
use Hampel\CloseApi\Exception\TransportException;
use Hampel\CloseApi\Http\DefaultRetryPolicy;
use Hampel\CloseApi\Tests\TestCase;
use Http\Client\Exception\NetworkException;
use PHPUnit\Framework\Attributes\Test;

/**
 * The retry behaviour, driven through the transport rather than by calling the
 * policy directly - the thing worth checking is that a retried request is
 * actually re-sent and that the wait actually happens.
 */
final class RetryTest extends TestCase
{
    #[Test]
    public function it_retries_a_rate_limited_request_and_returns_the_second_answer(): void
    {
        $this->queue(429, ['error' => 'slow down'], ['RateLimit' => 'limit=100, remaining=0, reset=1.5']);
        $this->queue(200, ['id' => 'lead_x']);

        $response = $this->transport(retryPolicy: new DefaultRetryPolicy())->get('lead/lead_x/');

        $this->assertSame('lead_x', $response['id']);
        $this->assertCount(2, $this->requests());
        $this->assertSame([1.5], $this->sleeper->slept, 'It waits exactly as long as Close asked.');
    }

    /**
     * A 429 is applied before the request is processed, so nothing happened and
     * repeating a write is safe. This is the one case where method does not
     * matter.
     */
    #[Test]
    public function it_retries_a_rate_limited_post(): void
    {
        $this->queue(429, [], ['RateLimit' => 'limit=100, remaining=0, reset=0.25']);
        $this->queue(201, ['id' => 'lead_new']);

        $response = $this->transport(retryPolicy: new DefaultRetryPolicy())->post('lead/', ['name' => 'Wayne']);

        $this->assertSame(201, $response->status);
        $this->assertCount(2, $this->requests());
    }

    #[Test]
    public function it_gives_up_once_the_attempts_are_spent(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->queue(429, [], ['RateLimit' => 'limit=100, remaining=0, reset=0.1']);
        }

        $this->expectException(RateLimitException::class);

        try {
            $this->transport(retryPolicy: new DefaultRetryPolicy(maxAttempts: 3))->get('lead/');
        } finally {
            $this->assertCount(3, $this->requests());
            $this->assertCount(2, $this->sleeper->slept, 'Three attempts means two waits.');
        }
    }

    /**
     * A library that silently blocks a request thread for minutes is worse than
     * one that says why it stopped. Close's enforcement windows are per
     * endpoint group and it does not publish them, so a long one is entirely
     * possible.
     */
    #[Test]
    public function it_refuses_to_sleep_through_a_window_longer_than_the_ceiling(): void
    {
        $this->queue(429, [], ['RateLimit' => 'limit=100, remaining=0, reset=300']);

        $this->expectException(RateLimitException::class);

        try {
            $this->transport(retryPolicy: new DefaultRetryPolicy(maxDelay: 60.0))->get('lead/');
        } finally {
            $this->assertCount(1, $this->requests());
            $this->assertSame([], $this->sleeper->slept);
        }
    }

    #[Test]
    public function it_retries_a_server_error_on_an_idempotent_method(): void
    {
        $this->queue(503, ['error' => 'unavailable']);
        $this->queue(200, ['id' => 'lead_x']);

        $response = $this->transport(retryPolicy: new DefaultRetryPolicy())->get('lead/lead_x/');

        $this->assertSame('lead_x', $response['id']);
        $this->assertCount(2, $this->requests());
        $this->assertCount(1, $this->sleeper->slept);
    }

    /**
     * A POST that 500s may already have been applied, with the failure in the
     * response path rather than the operation. Retrying it is how duplicate
     * leads get created.
     */
    #[Test]
    public function it_does_not_retry_a_server_error_on_a_post(): void
    {
        $this->queue(500, ['error' => 'boom']);

        $this->expectException(ServerException::class);

        try {
            $this->transport(retryPolicy: new DefaultRetryPolicy())->post('lead/', ['name' => 'Wayne']);
        } finally {
            $this->assertCount(1, $this->requests());
        }
    }

    #[Test]
    public function it_retries_a_connection_failure_on_an_idempotent_method(): void
    {
        $this->http->addException(new NetworkException(
            'Connection reset',
            $this->psr17->createRequest('GET', 'https://api.close.com/api/v1/me/'),
        ));
        $this->queue(200, ['id' => 'user_x']);

        $response = $this->transport(retryPolicy: new DefaultRetryPolicy())->get('me/');

        $this->assertSame('user_x', $response['id']);
        $this->assertCount(2, $this->requests());
    }

    #[Test]
    public function it_does_not_retry_a_connection_failure_on_a_post(): void
    {
        $this->http->setDefaultException(new NetworkException(
            'Connection reset',
            $this->psr17->createRequest('POST', 'https://api.close.com/api/v1/lead/'),
        ));

        $this->expectException(TransportException::class);

        try {
            $this->transport(retryPolicy: new DefaultRetryPolicy())->post('lead/', ['name' => 'Wayne']);
        } finally {
            $this->assertCount(1, $this->requests());
        }
    }

    /**
     * These will fail again in exactly the same way. Burning the budget on one
     * only delays the error the caller needs to see.
     */
    #[Test]
    public function it_does_not_retry_a_client_error(): void
    {
        $this->queue(400, ['error' => 'bad']);

        $this->expectException(BadRequestException::class);

        try {
            $this->transport(retryPolicy: new DefaultRetryPolicy())->get('lead/');
        } finally {
            $this->assertCount(1, $this->requests());
            $this->assertSame([], $this->sleeper->slept);
        }
    }

    #[Test]
    public function it_does_not_retry_a_not_found(): void
    {
        $this->queue(404, ['error' => 'gone']);

        $this->expectException(NotFoundException::class);

        try {
            $this->transport(retryPolicy: new DefaultRetryPolicy())->get('lead/lead_x/');
        } finally {
            $this->assertCount(1, $this->requests());
        }
    }

    #[Test]
    public function backoff_grows_and_stays_within_the_ceiling(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->queue(503, []);
        }

        try {
            $this->transport(retryPolicy: new DefaultRetryPolicy(
                maxAttempts: 5,
                baseDelay: 1.0,
                maxDelay: 4.0,
            ))->get('lead/');
        } catch (ServerException) {
            // expected
        }

        $this->assertCount(4, $this->sleeper->slept);

        // Full jitter draws uniformly over [0, base * 2^(n-1)], so the only
        // safe assertion per attempt is the ceiling - which is the property
        // that matters, since an unbounded wait is the failure mode.
        foreach ($this->sleeper->slept as $n => $delay) {
            $this->assertGreaterThanOrEqual(0.0, $delay);
            $this->assertLessThanOrEqual(min(4.0, 1.0 * 2 ** $n), $delay);
        }
    }

    #[Test]
    public function a_single_attempt_policy_never_retries(): void
    {
        $this->queue(429, [], ['RateLimit' => 'limit=1, remaining=0, reset=0.1']);

        $this->expectException(RateLimitException::class);

        try {
            $this->transport(retryPolicy: new DefaultRetryPolicy(maxAttempts: 1))->get('lead/');
        } finally {
            $this->assertCount(1, $this->requests());
        }
    }
}
