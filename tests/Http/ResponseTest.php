<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Tests\Http;

use Hampel\CloseApi\Exception\RuntimeException;
use Hampel\CloseApi\Http\RateLimit;
use Hampel\CloseApi\Http\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    #[Test]
    public function it_exposes_the_whole_body(): void
    {
        $response = new Response(['id' => 'lead_x', 'name' => 'Wayne Enterprises'], 200);

        $this->assertSame(['id' => 'lead_x', 'name' => 'Wayne Enterprises'], $response->all());
        $this->assertSame(200, $response->status);
    }

    #[Test]
    public function it_reads_keys_through_array_access_and_get(): void
    {
        $response = new Response(['id' => 'lead_x'], 200);

        $this->assertSame('lead_x', $response['id']);
        $this->assertSame('lead_x', $response->get('id'));
        $this->assertNull($response['missing']);
        $this->assertSame('fallback', $response->get('missing', 'fallback'));
        $this->assertTrue($response->has('id'));
        $this->assertFalse($response->has('missing'));
    }

    /**
     * Close names custom fields `custom.cf_xxxxxxxx`. A response that supported
     * dot notation could not read one, which is why keys here are literal.
     */
    #[Test]
    public function a_dot_is_part_of_the_key_not_a_path_separator(): void
    {
        $response = new Response(['custom.cf_abc123' => 'Website contact form'], 200);

        $this->assertSame('Website contact form', $response['custom.cf_abc123']);
        $this->assertSame('Website contact form', $response->get('custom.cf_abc123'));
    }

    #[Test]
    public function it_recognises_a_list_response(): void
    {
        $response = new Response(['data' => [['id' => 'lead_a']], 'has_more' => true], 200);

        $this->assertTrue($response->isList());
        $this->assertTrue($response->hasMore());
        $this->assertSame([['id' => 'lead_a']], $response->data());
    }

    /**
     * The Advanced Filtering API returns {data, cursor} with no has_more, so a
     * check for has_more alone treats a whole search result as a single object
     * and hands back the envelope instead of the records.
     */
    #[Test]
    public function it_recognises_the_cursor_envelope_as_a_list_too(): void
    {
        $response = new Response(['data' => [['id' => 'cont_a']], 'cursor' => 'abc'], 200);

        $this->assertTrue($response->isList());
        $this->assertSame([['id' => 'cont_a']], $response->data());
        $this->assertFalse($response->hasMore(), 'Cursor responses carry no has_more.');
        $this->assertSame('abc', $response->cursor());
    }

    #[Test]
    public function the_last_cursor_page_is_still_a_list(): void
    {
        $response = new Response(['data' => [], 'cursor' => null], 200);

        $this->assertTrue($response->isList());
        $this->assertSame([], $response->data());
    }

    /**
     * Observed against the live API on 2026-09-23: custom_field/custom_object_type/
     * and custom_object_type/ answer with data and nothing else - no has_more,
     * no cursor. Requiring a pagination marker made data() hand back the
     * envelope and count() report the number of keys, silently, with no error
     * anywhere.
     */
    #[Test]
    public function a_bare_data_envelope_with_no_pagination_marker_is_still_a_list(): void
    {
        $response = new Response(['data' => [['id' => 'cf_a'], ['id' => 'cf_b']]], 200);

        $this->assertTrue($response->isList());
        $this->assertSame([['id' => 'cf_a'], ['id' => 'cf_b']], $response->data());
        $this->assertCount(2, $response->data());
    }

    #[Test]
    public function an_empty_bare_data_envelope_is_a_list_of_nothing(): void
    {
        $response = new Response(['data' => []], 200);

        $this->assertTrue($response->isList());
        $this->assertSame([], $response->data());
    }

    /**
     * Every individual Close object carries an id and no envelope does, so that
     * is what separates a bare envelope from an object with a data field.
     */
    #[Test]
    public function an_object_carrying_a_data_field_is_not_an_envelope(): void
    {
        $response = new Response(['id' => 'cotype_x', 'name' => 'Product', 'data' => ['a', 'b']], 200);

        $this->assertFalse($response->isList());
        $this->assertSame(['id' => 'cotype_x', 'name' => 'Product', 'data' => ['a', 'b']], $response->data());
    }

    /**
     * lead/, task/ and opportunity/ send counts and aggregates alongside the
     * records - opportunity/ adds eighteen of them. Extra keys must not stop a
     * response being recognised as a list.
     */
    #[Test]
    public function extra_envelope_keys_do_not_stop_it_being_a_list(): void
    {
        $response = new Response([
            'data' => [['id' => 'oppo_a']],
            'has_more' => false,
            'total_results' => 1,
            'expected_value_annual' => 0,
            'total_value_monthly_formatted' => '$0',
        ], 200);

        $this->assertTrue($response->isList());
        $this->assertSame([['id' => 'oppo_a']], $response->data());
        $this->assertSame(1, $response['total_results']);
    }

    /**
     * `data` alone is not a list envelope - a single object could legitimately
     * have a field called data. The pagination marker beside it is what makes
     * it one.
     */
    #[Test]
    public function it_does_not_mistake_a_data_field_for_a_list_envelope(): void
    {
        $response = new Response(['id' => 'x', 'data' => ['nested' => true]], 200);

        $this->assertFalse($response->isList());
        $this->assertSame(['id' => 'x', 'data' => ['nested' => true]], $response->data());
    }

    #[Test]
    public function data_returns_the_object_itself_when_the_response_is_not_a_list(): void
    {
        $response = new Response(['id' => 'lead_x'], 200);

        $this->assertSame(['id' => 'lead_x'], $response->data());
    }

    #[Test]
    public function has_more_is_false_when_the_response_is_not_a_list(): void
    {
        $this->assertFalse((new Response(['id' => 'lead_x'], 200))->hasMore());
    }

    #[Test]
    public function it_reads_a_cursor(): void
    {
        $this->assertSame('abc', (new Response(['cursor' => 'abc'], 200))->cursor());
    }

    /**
     * Close signals the last page with a null cursor.
     */
    #[Test]
    public function a_null_or_empty_cursor_means_the_last_page(): void
    {
        $this->assertNull((new Response(['cursor' => null], 200))->cursor());
        $this->assertNull((new Response(['cursor' => ''], 200))->cursor());
        $this->assertNull((new Response([], 200))->cursor());
    }

    #[Test]
    public function it_counts_and_iterates_the_top_level(): void
    {
        $response = new Response(['a' => 1, 'b' => 2], 200);

        $this->assertCount(2, $response);
        $this->assertSame(['a' => 1, 'b' => 2], iterator_to_array($response));
    }

    #[Test]
    public function it_carries_the_rate_limit_state(): void
    {
        $limit = new RateLimit(100, 99, 5.0);

        $this->assertSame($limit, (new Response([], 200, $limit))->rateLimit);
        $this->assertNull((new Response([], 200))->rateLimit);
    }

    #[Test]
    public function it_serialises_back_to_the_body(): void
    {
        $this->assertSame('{"id":"lead_x"}', json_encode(new Response(['id' => 'lead_x'], 200)));
    }

    #[Test]
    public function it_refuses_to_be_written_to(): void
    {
        $response = new Response(['id' => 'lead_x'], 200);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('read-only');

        $response['id'] = 'lead_y';
    }

    #[Test]
    public function it_refuses_to_have_a_key_removed(): void
    {
        $response = new Response(['id' => 'lead_x'], 200);

        $this->expectException(RuntimeException::class);

        unset($response['id']);
    }
}
