<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Tests\Http;

use Hampel\CloseApi\Exception\InvalidArgumentException;
use Hampel\CloseApi\Http\Query;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class QueryTest extends TestCase
{
    #[Test]
    public function it_builds_an_empty_string_for_no_parameters(): void
    {
        $this->assertSame('', Query::build([]));
    }

    #[Test]
    public function it_encodes_scalars(): void
    {
        $this->assertSame('_limit=100&_skip=0', Query::build(['_limit' => 100, '_skip' => 0]));
    }

    /**
     * The behaviour this class exists for. http_build_query would render this
     * as id__in%5B0%5D=a&id__in%5B1%5D=b, which Close does not understand and
     * does not reject - it returns the unfiltered collection instead, so the
     * failure is a silently wrong result rather than an error.
     */
    #[Test]
    public function it_joins_a_list_with_commas_rather_than_repeating_the_key(): void
    {
        $this->assertSame(
            'id__in=lead_a%2Clead_b%2Clead_c',
            Query::build(['id__in' => ['lead_a', 'lead_b', 'lead_c']]),
        );
    }

    #[Test]
    public function it_encodes_the_fields_parameter_the_same_way(): void
    {
        $this->assertSame('_fields=id%2Cname', Query::build(['_fields' => ['id', 'name']]));
    }

    /**
     * PHP casts true to "1" and false to "", and Close reads the second as an
     * absent parameter rather than as false. Both are wrong here.
     */
    #[Test]
    public function it_renders_booleans_as_the_literals_close_expects(): void
    {
        $this->assertSame('a=true&b=false', Query::build(['a' => true, 'b' => false]));
    }

    #[Test]
    public function it_drops_nulls_rather_than_sending_an_empty_value(): void
    {
        $this->assertSame('kept=1', Query::build(['kept' => 1, 'dropped' => null]));
    }

    #[Test]
    public function it_drops_nulls_from_within_a_list(): void
    {
        $this->assertSame('ids=a%2Cb', Query::build(['ids' => ['a', null, 'b']]));
    }

    #[Test]
    public function it_formats_dates_as_atom(): void
    {
        $date = new \DateTimeImmutable('2026-09-05 14:30:00', new \DateTimeZone('UTC'));

        $this->assertSame(
            ['date_created__gte' => '2026-09-05T14:30:00+00:00'],
            Query::normalise(['date_created__gte' => $date]),
        );
    }

    #[Test]
    public function it_unwraps_a_backed_enum(): void
    {
        $this->assertSame(['status' => 'draft'], Query::normalise(['status' => QueryTestStatus::Draft]));
    }

    #[Test]
    public function it_encodes_reserved_characters_in_keys_and_values(): void
    {
        $this->assertSame(
            'query=name%3A%22Wayne%20Enterprises%22',
            Query::build(['query' => 'name:"Wayne Enterprises"']),
        );
    }

    #[Test]
    public function it_rejects_a_nested_array(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Query parameter "ids" cannot contain a nested array.');

        Query::build(['ids' => [['a']]]);
    }

    #[Test]
    public function it_rejects_an_object_it_cannot_render(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Query parameter "thing" must be a scalar');

        Query::build(['thing' => new \stdClass()]);
    }
}

enum QueryTestStatus: string
{
    case Draft = 'draft';
}
