<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Tests\Support;

use Hampel\CloseApi\Auth\ApiKey;
use Hampel\CloseApi\Close;
use Hampel\CloseApi\Exception\RuntimeException;
use Hampel\CloseApi\Http\Transport;
use Hampel\CloseApi\Support\Psr17Discovery;
use Hampel\CloseApi\Tests\TestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

final class Psr17DiscoveryTest extends TestCase
{
    #[Test]
    public function a_transport_can_be_built_without_naming_a_factory(): void
    {
        $this->queue(200, ['id' => 'user_me']);

        $transport = new Transport(new ApiKey('api_test'), $this->http);
        $response = $transport->post('lead/', ['name' => 'Wayne Enterprises']);

        // the found factories build a request that is actually sent, body and all
        $this->assertSame('user_me', $response['id']);
        $this->assertSame('https://api.close.com/api/v1/lead/', (string) $this->request()->getUri());
        $this->assertSame('{"name":"Wayne Enterprises"}', (string) $this->request()->getBody());
    }

    #[Test]
    public function the_short_form_needs_nothing_but_a_key_and_a_client(): void
    {
        $this->queue(200, ['id' => 'user_me']);

        $this->assertSame('user_me', Close::withApiKey('api_test', $this->http)->users()->me()['id']);
    }

    /**
     * nyholm/psr7 is the only implementation in the development tree, so it is
     * what is found here. Guzzle and Diactoros are exercised through from().
     */
    #[Test]
    public function in_the_development_tree_it_finds_nyholm(): void
    {
        [$request, $stream] = Psr17Discovery::find();

        $this->assertInstanceOf(Psr17Factory::class, $request);
        $this->assertSame($request, $stream, 'Nyholm ships one class for both roles; it should be built once.');
    }

    #[Test]
    public function a_factory_that_is_given_is_used_rather_than_discovered(): void
    {
        $this->queue();

        $given = new class () implements RequestFactoryInterface {
            public int $calls = 0;

            public function createRequest(string $method, $uri): RequestInterface
            {
                $this->calls++;

                return (new Psr17Factory())->createRequest($method, $uri);
            }
        };

        $transport = new Transport(new ApiKey('api_test'), $this->http, $given, $this->psr17);
        $transport->get('me/');

        $this->assertSame(1, $given->calls);
    }

    /**
     * Passing only one factory still discovers the other, rather than failing or
     * discarding the one that was given.
     */
    #[Test]
    public function one_given_factory_is_kept_and_the_other_is_found(): void
    {
        $this->queue();

        $given = new CountingStreamFactory();

        $transport = new Transport(new ApiKey('api_test'), $this->http, null, $given);
        $transport->post('lead/', ['name' => 'x']);

        $this->assertSame(1, $given->calls);
        $this->assertSame('{"name":"x"}', (string) $this->request()->getBody());
    }

    /**
     * The not-found path cannot be reached with a factory installed, so it is
     * reached through the list instead.
     */
    #[Test]
    public function nothing_found_says_what_to_pass_and_what_to_install(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(RequestFactoryInterface::class);
        $this->expectExceptionMessage('guzzlehttp/psr7');

        Psr17Discovery::from([['No\Such\Factory', 'No\Such\Factory']]);
    }

    /**
     * A class that exists but is not a factory is skipped, not returned - the
     * instanceof is what makes discovery by name safe.
     */
    #[Test]
    public function a_class_that_exists_but_is_not_a_factory_is_skipped(): void
    {
        $this->expectException(RuntimeException::class);

        Psr17Discovery::from([[\stdClass::class, \stdClass::class]]);
    }

    #[Test]
    public function later_candidates_are_tried_when_earlier_ones_are_absent(): void
    {
        [$request] = Psr17Discovery::from([
            ['No\Such\Factory', 'No\Such\Factory'],
            [\stdClass::class, \stdClass::class],
            [Psr17Factory::class, Psr17Factory::class],
        ]);

        $this->assertInstanceOf(Psr17Factory::class, $request);
    }

    /**
     * Diactoros splits the two roles into separate classes; a pair of different
     * classes must build each once, not reuse one for both.
     */
    #[Test]
    public function a_split_pair_builds_each_class(): void
    {
        [$request, $stream] = Psr17Discovery::from([
            [Psr17Factory::class, CountingStreamFactory::class],
        ]);

        $this->assertInstanceOf(Psr17Factory::class, $request);
        $this->assertInstanceOf(CountingStreamFactory::class, $stream);
    }
}

/**
 * A stream factory in a class of its own, standing in for an implementation that
 * splits the two roles, and counting its calls so a test can tell it was used.
 */
final class CountingStreamFactory implements StreamFactoryInterface
{
    public int $calls = 0;

    private Psr17Factory $inner;

    public function __construct()
    {
        $this->inner = new Psr17Factory();
    }

    public function createStream(string $content = ''): StreamInterface
    {
        $this->calls++;

        return $this->inner->createStream($content);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        return $this->inner->createStreamFromFile($filename, $mode);
    }

    public function createStreamFromResource($resource): StreamInterface
    {
        return $this->inner->createStreamFromResource($resource);
    }
}
