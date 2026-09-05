<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Tests\Auth;

use Hampel\CloseApi\Auth\ApiKey;
use Hampel\CloseApi\Auth\BearerToken;
use Hampel\CloseApi\Exception\InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class AuthenticationTest extends TestCase
{
    private function request(): RequestInterface
    {
        return (new Psr17Factory())->createRequest('GET', 'https://api.close.com/api/v1/me/');
    }

    /**
     * The trailing colon is the whole point: Close documents the key as the
     * username with an empty password, and encoding the key alone produces a
     * 401 that reads like a bad key.
     */
    #[Test]
    public function an_api_key_is_basic_auth_with_an_empty_password(): void
    {
        $request = (new ApiKey('api_abc123'))->authenticate($this->request());

        $this->assertSame('Basic '.base64_encode('api_abc123:'), $request->getHeaderLine('Authorization'));
        $this->assertSame('api_abc123:', base64_decode(substr($request->getHeaderLine('Authorization'), 6)));
    }

    #[Test]
    public function an_api_key_is_trimmed(): void
    {
        $request = (new ApiKey("  api_abc123\n"))->authenticate($this->request());

        $this->assertSame('Basic '.base64_encode('api_abc123:'), $request->getHeaderLine('Authorization'));
    }

    #[Test]
    public function an_empty_api_key_is_refused_before_a_request_is_made(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A Close API key is required.');

        new ApiKey('   ');
    }

    /**
     * describe() is written to a log on every request, so it must carry enough
     * to explain a 401 and none of the secret.
     */
    #[Test]
    public function an_api_key_never_describes_itself_with_the_key(): void
    {
        $key = 'api_abcdefghijklmnop';
        $description = (new ApiKey($key))->describe();

        $this->assertStringNotContainsString('abcdefghijklmnop', $description);
        $this->assertStringContainsString('api_', $description, 'The prefix is what explains a 401.');
        $this->assertStringContainsString((string) strlen($key), $description);
    }

    #[Test]
    public function a_bearer_token_goes_in_the_authorization_header(): void
    {
        $request = (new BearerToken('tok_xyz'))->authenticate($this->request());

        $this->assertSame('Bearer tok_xyz', $request->getHeaderLine('Authorization'));
    }

    #[Test]
    public function an_empty_bearer_token_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BearerToken('');
    }

    #[Test]
    public function a_bearer_token_never_describes_itself_with_the_token(): void
    {
        $description = (new BearerToken('tok_supersecret'))->describe();

        $this->assertStringNotContainsString('supersecret', $description);
    }

    #[Test]
    public function authenticating_leaves_the_rest_of_the_request_alone(): void
    {
        $original = $this->request()->withHeader('Accept', 'application/json');
        $authenticated = (new ApiKey('api_abc'))->authenticate($original);

        $this->assertSame('application/json', $authenticated->getHeaderLine('Accept'));
        $this->assertSame('GET', $authenticated->getMethod());
        $this->assertFalse($original->hasHeader('Authorization'), 'PSR-7 messages are immutable.');
    }
}
