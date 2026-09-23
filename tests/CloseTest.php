<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Tests;

use Hampel\CloseApi\Auth\BearerToken;
use Hampel\CloseApi\Close;
use Hampel\CloseApi\Http\DefaultRetryPolicy;
use Hampel\CloseApi\Exception\InvalidArgumentException;
use Hampel\CloseApi\Resource\Activities;
use Hampel\CloseApi\Resource\Activity\Calls;
use Hampel\CloseApi\Resource\Activity\Emails;
use Hampel\CloseApi\Resource\Activity\Meetings;
use Hampel\CloseApi\Resource\Activity\Messages;
use Hampel\CloseApi\Resource\Activity\Notes;
use Hampel\CloseApi\Resource\Contacts;
use Hampel\CloseApi\Resource\CustomFields;
use Hampel\CloseApi\Resource\CustomFieldType;
use Hampel\CloseApi\Resource\Leads;
use Hampel\CloseApi\Resource\Opportunities;
use Hampel\CloseApi\Resource\Search;
use Hampel\CloseApi\Resource\Statuses;
use Hampel\CloseApi\Resource\StatusType;
use Hampel\CloseApi\Resource\Tasks;
use Hampel\CloseApi\Resource\Users;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class CloseTest extends TestCase
{
    /**
     * @return array<string, array{string, class-string}>
     */
    public static function accessors(): array
    {
        return [
            'leads' => ['leads', Leads::class],
            'contacts' => ['contacts', Contacts::class],
            'opportunities' => ['opportunities', Opportunities::class],
            'tasks' => ['tasks', Tasks::class],
            'users' => ['users', Users::class],
            'activities' => ['activities', Activities::class],
            'notes' => ['notes', Notes::class],
            'emails' => ['emails', Emails::class],
            'calls' => ['calls', Calls::class],
            'messages' => ['messages', Messages::class],
            'meetings' => ['meetings', Meetings::class],
            'search' => ['search', Search::class],
        ];
    }

    /**
     * @param  class-string  $expected
     */
    #[Test]
    #[DataProvider('accessors')]
    public function it_exposes_each_resource(string $method, string $expected): void
    {
        $close = new Close($this->transport());

        $this->assertInstanceOf($expected, $close->{$method}());
    }

    #[Test]
    public function custom_fields_are_addressed_by_type(): void
    {
        $close = new Close($this->transport());

        foreach (CustomFieldType::cases() as $type) {
            $resource = $close->customFields($type);

            $this->assertInstanceOf(CustomFields::class, $resource);
            $this->assertSame($type, $resource->type());
        }
    }

    #[Test]
    public function statuses_come_in_both_kinds(): void
    {
        $close = new Close($this->transport());

        $this->assertInstanceOf(Statuses::class, $close->leadStatuses());
        $this->assertSame(StatusType::Lead, $close->leadStatuses()->type());
        $this->assertSame(StatusType::Opportunity, $close->opportunityStatuses()->type());
    }

    /**
     * The escape hatch. With most of Close's 302 operations deliberately
     * unwrapped, reaching the transport has to be a supported route rather than
     * something a consumer has to work around.
     */
    #[Test]
    public function the_transport_is_public(): void
    {
        $transport = $this->transport();

        $this->assertSame($transport, (new Close($transport))->transport());
    }

    #[Test]
    public function it_builds_a_client_from_an_api_key_and_a_given_http_client(): void
    {
        $close = Close::withKey('api_test', $this->http);

        $this->queue(200, ['id' => 'user_me']);

        $this->assertSame('user_me', $close->users()->me()['id']);
        $this->assertSame(
            'Basic '.base64_encode('api_test:'),
            $this->request()->getHeaderLine('Authorization'),
        );
    }

    #[Test]
    public function it_builds_a_client_from_any_authentication(): void
    {
        $close = Close::with(new BearerToken('tok_abc'), $this->http);

        $this->queue(200, ['id' => 'user_me']);
        $close->users()->me();

        $this->assertSame('Bearer tok_abc', $this->request()->getHeaderLine('Authorization'));
    }

    /**
     * The policy decides how long to wait and the sleeper does the waiting, so
     * exposing one without the other left an integration able to configure
     * retries but not to test them without its suite really sleeping. The only
     * way round it was to abandon the short form and build a Transport by hand.
     */
    #[Test]
    public function the_short_form_takes_a_sleeper_so_retries_can_be_tested_without_waiting(): void
    {
        $this->queue(429, [], ['RateLimit' => 'limit=100, remaining=0, reset=30']);
        $this->queue(200, ['id' => 'user_me']);

        $started = microtime(true);

        $close = Close::withKey(
            'api_test',
            $this->http,
            retryPolicy: new DefaultRetryPolicy(),
            sleeper: $this->sleeper,
        );

        $this->assertSame('user_me', $close->users()->me()['id']);
        $this->assertSame([30.0], $this->sleeper->slept, 'It waited exactly as long as Close asked.');
        $this->assertLessThan(
            5.0,
            microtime(true) - $started,
            'And did not actually sleep for 30 seconds doing it.',
        );
    }

    #[Test]
    public function without_a_sleeper_the_short_form_still_builds_a_working_client(): void
    {
        $this->queue(200, ['id' => 'user_me']);

        $this->assertSame('user_me', Close::withKey('api_test', $this->http)->users()->me()['id']);
    }

    #[Test]
    public function an_empty_api_key_fails_at_construction_rather_than_on_the_first_call(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Close::withKey('', $this->http);
    }
}
