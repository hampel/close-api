<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Http;

use BackedEnum;
use DateTimeInterface;
use Hampel\CloseApi\Exception\InvalidArgumentException;
use Stringable;

/**
 * Builds Close query strings.
 *
 * Not `http_build_query`, for one reason that matters: Close takes repeated
 * values as a comma-separated list in a single parameter — `id__in=a,b,c` —
 * and `http_build_query` would render that as `id__in[0]=a&id__in[1]=b`, which
 * Close does not understand and does not reject. It returns the unfiltered
 * collection instead, so the failure is a silently wrong result rather than an
 * error.
 */
final class Query
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function build(array $parameters): string
    {
        $pairs = [];

        foreach (self::normalise($parameters) as $key => $value) {
            $pairs[] = rawurlencode($key).'='.rawurlencode($value);
        }

        return implode('&', $pairs);
    }

    /**
     * Flatten parameters to the strings Close expects, dropping nulls.
     *
     * A null is an absent parameter, not an empty one. Sending `foo=` where a
     * caller passed null would have Close filter on the empty string.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, string>
     */
    public static function normalise(array $parameters): array
    {
        $normalised = [];

        foreach ($parameters as $key => $value) {
            if ($value === null) {
                continue;
            }

            $normalised[$key] = self::value($key, $value);
        }

        return $normalised;
    }

    private static function value(string $key, mixed $value): string
    {
        if (is_array($value)) {
            $parts = [];

            foreach ($value as $item) {
                if ($item === null) {
                    continue;
                }

                if (is_array($item)) {
                    throw new InvalidArgumentException(
                        sprintf('Query parameter "%s" cannot contain a nested array.', $key),
                    );
                }

                $parts[] = self::value($key, $item);
            }

            return implode(',', $parts);
        }

        // Close's filters are literal `true` and `false`, so the PHP cast to
        // "1" and "" is wrong in both directions: "" reads as absent.
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if (is_int($value) || is_float($value) || is_string($value) || $value instanceof Stringable) {
            return (string) $value;
        }

        throw new InvalidArgumentException(sprintf(
            'Query parameter "%s" must be a scalar, an enum, a date or a list of those; %s given.',
            $key,
            get_debug_type($value),
        ));
    }
}
