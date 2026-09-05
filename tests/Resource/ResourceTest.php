<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Tests\Resource;

use Hampel\CloseApi\Close;
use Hampel\CloseApi\Exception\InvalidArgumentException;
use Hampel\CloseApi\Resource\CustomFieldType;
use Hampel\CloseApi\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The regression net for path building.
 *
 * Every resource method resolves to exactly one method and URL, and this is
 * where that is pinned. A wrong path is the single most likely defect in a
 * package like this and the one a consumer can do least about, so add a case
 * here for any endpoint method added to a resource.
 */
final class ResourceTest extends TestCase
{
    private function close(): Close
    {
        return new Close($this->transport());
    }

    /**
     * @return array<string, array{string, string, callable(Close): mixed}>
     */
    public static function endpoints(): array
    {
        return [
            'leads list' => ['GET', 'lead/', fn (Close $c) => $c->leads()->list()],
            'leads get' => ['GET', 'lead/lead_abc/', fn (Close $c) => $c->leads()->get('lead_abc')],
            'leads create' => ['POST', 'lead/', fn (Close $c) => $c->leads()->create(['name' => 'x'])],
            'leads update' => ['PUT', 'lead/lead_abc/', fn (Close $c) => $c->leads()->update('lead_abc', ['name' => 'y'])],
            'leads delete' => ['DELETE', 'lead/lead_abc/', fn (Close $c) => $c->leads()->delete('lead_abc')],
            'leads merge' => ['POST', 'lead/merge/', fn (Close $c) => $c->leads()->merge('lead_a', 'lead_b')],

            'contacts list' => ['GET', 'contact/', fn (Close $c) => $c->contacts()->list()],
            'contacts get' => ['GET', 'contact/cont_abc/', fn (Close $c) => $c->contacts()->get('cont_abc')],
            'contacts create' => ['POST', 'contact/', fn (Close $c) => $c->contacts()->create(['lead_id' => 'lead_a'])],
            'contacts update' => ['PUT', 'contact/cont_abc/', fn (Close $c) => $c->contacts()->update('cont_abc', [])],
            'contacts delete' => ['DELETE', 'contact/cont_abc/', fn (Close $c) => $c->contacts()->delete('cont_abc')],

            'opportunities list' => ['GET', 'opportunity/', fn (Close $c) => $c->opportunities()->list()],
            'opportunities get' => ['GET', 'opportunity/oppo_abc/', fn (Close $c) => $c->opportunities()->get('oppo_abc')],
            'opportunities create' => ['POST', 'opportunity/', fn (Close $c) => $c->opportunities()->create([])],
            'opportunities update' => ['PUT', 'opportunity/oppo_abc/', fn (Close $c) => $c->opportunities()->update('oppo_abc', [])],
            'opportunities delete' => ['DELETE', 'opportunity/oppo_abc/', fn (Close $c) => $c->opportunities()->delete('oppo_abc')],

            'tasks list' => ['GET', 'task/', fn (Close $c) => $c->tasks()->list()],
            'tasks get' => ['GET', 'task/task_abc/', fn (Close $c) => $c->tasks()->get('task_abc')],
            'tasks create' => ['POST', 'task/', fn (Close $c) => $c->tasks()->create(['_type' => 'lead'])],
            'tasks update' => ['PUT', 'task/task_abc/', fn (Close $c) => $c->tasks()->update('task_abc', [])],
            'tasks delete' => ['DELETE', 'task/task_abc/', fn (Close $c) => $c->tasks()->delete('task_abc')],

            'users list' => ['GET', 'user/', fn (Close $c) => $c->users()->list()],
            'users get' => ['GET', 'user/user_abc/', fn (Close $c) => $c->users()->get('user_abc')],
            'users me' => ['GET', 'me/', fn (Close $c) => $c->users()->me()],
            'users availability' => ['GET', 'user/availability/', fn (Close $c) => $c->users()->availability()],

            'activity feed' => ['GET', 'activity/', fn (Close $c) => $c->activities()->list()],

            'notes list' => ['GET', 'activity/note/', fn (Close $c) => $c->notes()->list()],
            'notes get' => ['GET', 'activity/note/acti_abc/', fn (Close $c) => $c->notes()->get('acti_abc')],
            'notes create' => ['POST', 'activity/note/', fn (Close $c) => $c->notes()->create(['lead_id' => 'lead_a'])],
            'notes update' => ['PUT', 'activity/note/acti_abc/', fn (Close $c) => $c->notes()->update('acti_abc', [])],
            'notes delete' => ['DELETE', 'activity/note/acti_abc/', fn (Close $c) => $c->notes()->delete('acti_abc')],

            'emails create' => ['POST', 'activity/email/', fn (Close $c) => $c->emails()->create(['lead_id' => 'l', 'status' => 'draft'])],
            'calls list' => ['GET', 'activity/call/', fn (Close $c) => $c->calls()->list()],
            'sms list' => ['GET', 'activity/sms/', fn (Close $c) => $c->messages()->list()],
            'meetings list' => ['GET', 'activity/meeting/', fn (Close $c) => $c->meetings()->list()],

            // Singular, per the OpenAPI spec. The plural is not an endpoint.
            'custom fields lead' => ['GET', 'custom_field/lead/', fn (Close $c) => $c->customFields(CustomFieldType::Lead)->list()],
            'custom fields contact' => ['GET', 'custom_field/contact/', fn (Close $c) => $c->customFields(CustomFieldType::Contact)->list()],
            'custom fields shared' => ['GET', 'custom_field/shared/', fn (Close $c) => $c->customFields(CustomFieldType::Shared)->list()],
            'custom fields get' => ['GET', 'custom_field/lead/cf_abc/', fn (Close $c) => $c->customFields(CustomFieldType::Lead)->get('cf_abc')],
            'custom field association' => [
                'GET',
                'custom_field/shared/cf_abc/association/lead/',
                fn (Close $c) => $c->customFields(CustomFieldType::Shared)->association('cf_abc', CustomFieldType::Lead),
            ],

            'lead statuses' => ['GET', 'status/lead/', fn (Close $c) => $c->leadStatuses()->list()],
            'opportunity statuses' => ['GET', 'status/opportunity/', fn (Close $c) => $c->opportunityStatuses()->list()],
            'lead status get' => ['GET', 'status/lead/stat_abc/', fn (Close $c) => $c->leadStatuses()->get('stat_abc')],

            'search' => ['POST', 'data/search/', fn (Close $c) => $c->search()->query(['type' => 'and'])],
        ];
    }

    /**
     * @param  callable(Close): mixed  $call
     */
    #[Test]
    #[DataProvider('endpoints')]
    public function it_resolves_to_the_documented_method_and_path(string $method, string $path, callable $call): void
    {
        $this->queue(200, ['data' => [], 'has_more' => false]);

        $call($this->close());

        $request = $this->request();

        $this->assertSame($method, $request->getMethod());
        $this->assertSame('https://api.close.com/api/v1/'.$path, (string) $request->getUri());
    }

    #[Test]
    public function a_query_reaches_the_url(): void
    {
        $this->queue(200, ['data' => [], 'has_more' => false]);

        $this->close()->leads()->list(['_limit' => 10, 'query' => 'name:Wayne']);

        $this->assertSame(
            'https://api.close.com/api/v1/lead/?_limit=10&query=name%3AWayne',
            (string) $this->request()->getUri(),
        );
    }

    #[Test]
    public function a_payload_reaches_the_body(): void
    {
        $this->queue(201, ['id' => 'lead_new']);

        $this->close()->leads()->create(['name' => 'Wayne Enterprises', 'status_id' => 'stat_x']);

        $this->assertSame(
            ['name' => 'Wayne Enterprises', 'status_id' => 'stat_x'],
            json_decode((string) $this->request()->getBody(), true),
        );
    }

    // Id prefixes. Close prefixes ids by kind, and passing one resource's id to
    // another's method otherwise produces a 404 that says nothing about why.

    #[Test]
    public function it_refuses_an_id_belonging_to_another_resource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected a lead id starting with "lead_", got "cont_abc"');

        $this->close()->leads()->get('cont_abc');
    }

    #[Test]
    public function it_refuses_an_empty_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An id is required for contact.');

        $this->close()->contacts()->get('  ');
    }

    #[Test]
    public function it_checks_both_ids_of_a_merge(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->close()->leads()->merge('lead_a', 'oppo_b');
    }

    #[Test]
    public function a_merge_names_the_source_and_destination(): void
    {
        $this->queue();

        $this->close()->leads()->merge('lead_source', 'lead_dest');

        $this->assertSame(
            ['source' => 'lead_source', 'destination' => 'lead_dest'],
            json_decode((string) $this->request()->getBody(), true),
        );
    }

    // Operations Close does not offer are absent rather than failing at
    // runtime. Pinning the whole surface rather than probing for one missing
    // method also catches an operation added by accident.

    /**
     * @return array<string, array{class-string, list<string>}>
     */
    public static function surfaces(): array
    {
        return [
            // No create: Close has no POST /activity/meeting/, because meetings
            // arrive from Google Calendar or Outlook sync.
            'meetings' => [\Hampel\CloseApi\Resource\Activity\Meetings::class, [
                'list', 'paginate', 'get', 'update', 'delete',
            ]],
            // No create, update or delete: users are invited through the Close
            // UI and /user/ is read-only.
            'users' => [\Hampel\CloseApi\Resource\Users::class, [
                'list', 'get', 'me', 'availability',
            ]],
            'notes' => [\Hampel\CloseApi\Resource\Activity\Notes::class, [
                'list', 'paginate', 'get', 'update', 'delete', 'create',
            ]],
            // Read-only: an activity is created through the endpoint for its
            // own kind, never through the combined feed.
            'activity feed' => [\Hampel\CloseApi\Resource\Activities::class, [
                'list', 'paginate',
            ]],
        ];
    }

    /**
     * @param  class-string  $resource
     * @param  list<string>  $expected
     */
    #[Test]
    #[DataProvider('surfaces')]
    public function a_resource_exposes_exactly_the_operations_close_offers(string $resource, array $expected): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass($resource))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        $methods = array_values(array_diff($methods, ['__construct', 'type']));

        sort($methods);
        sort($expected);

        $this->assertSame($expected, $methods);
    }

    // _type is required on a task: the spec models CreateTask as a oneOf
    // discriminated on it.

    #[Test]
    public function creating_a_task_without_a_type_is_refused_before_the_request(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires "_type"');

        $this->close()->tasks()->create(['lead_id' => 'lead_a', 'text' => 'Call back']);
    }

    #[Test]
    public function a_bulk_task_update_sends_the_filter_as_a_query_and_the_change_as_a_body(): void
    {
        $this->queue();

        $this->close()->tasks()->updateWhere(['lead_id' => 'lead_a'], ['is_complete' => true]);

        $request = $this->request();

        $this->assertSame('PUT', $request->getMethod());
        $this->assertSame('https://api.close.com/api/v1/task/?lead_id=lead_a', (string) $request->getUri());
        $this->assertSame(['is_complete' => true], json_decode((string) $request->getBody(), true));
    }

    #[Test]
    public function a_bulk_task_update_refuses_an_empty_filter(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('matches every task in the organization');

        $this->close()->tasks()->updateWhere([], ['is_complete' => true]);
    }

    // Email status. "outbox" transmits; everything else records.

    #[Test]
    public function creating_an_email_requires_a_known_status(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"outbox" sends the message');

        $this->close()->emails()->create(['lead_id' => 'lead_a']);
    }

    #[Test]
    public function creating_an_email_refuses_a_status_close_does_not_define(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->close()->emails()->create(['lead_id' => 'lead_a', 'status' => 'pending']);
    }

    /**
     * All six, per the spec's CreateEmailActivity schema - "scheduled" and
     * "error" are easy to miss.
     */
    #[Test]
    public function every_status_the_spec_defines_is_accepted(): void
    {
        foreach (['inbox', 'draft', 'scheduled', 'outbox', 'sent', 'error'] as $status) {
            $this->http->reset();
            $this->queue(201, ['id' => 'acti_x']);

            $response = $this->close()->emails()->create(['lead_id' => 'lead_a', 'status' => $status]);

            $this->assertSame(201, $response->status);
        }
    }

    // Associations exist only on shared fields.

    #[Test]
    public function associating_a_non_shared_custom_field_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('applies to shared custom fields only');

        $this->close()->customFields(CustomFieldType::Lead)->associate('cf_abc', []);
    }

    #[Test]
    public function the_transport_is_reachable_for_endpoints_that_are_not_wrapped(): void
    {
        $this->queue(200, ['data' => [], 'has_more' => false]);

        $close = $this->close();
        $close->transport()->get('playbook/');

        $this->assertSame('https://api.close.com/api/v1/playbook/', (string) $this->request()->getUri());
    }
}
