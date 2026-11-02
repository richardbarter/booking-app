<?php

namespace App\Casts;

use App\Scheduling\Hours\PatternWeeks;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\ComparesCastableAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use JsonException;

/**
 * Stores a working pattern's 1 to 4 weeks of hours as JSON, building
 * PatternWeeks on the way in and out, so invalid hours fail in both
 * directions.
 *
 * @implements CastsAttributes<PatternWeeks, PatternWeeks|array<mixed>>
 */
class AsPatternWeeks implements CastsAttributes, ComparesCastableAttributes, SerializesCastableAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?PatternWeeks
    {
        return $value === null ? null : PatternWeeks::fromArray(self::decode($value, $key));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $weeks = $value instanceof PatternWeeks ? $value : PatternWeeks::fromArray($value);

        return json_encode($weeks->toArray(), JSON_THROW_ON_ERROR);
    }

    /**
     * Two stored values are the same when they hold the same hours, however
     * the JSON was spaced or ordered. A stored value that's no longer valid
     * always counts as changed, so a valid one can replace it.
     */
    public function compare(Model $model, string $key, mixed $firstValue, mixed $secondValue): bool
    {
        if ($firstValue === null || $secondValue === null) {
            return $firstValue === $secondValue;
        }

        try {
            return PatternWeeks::fromArray(self::decode($firstValue, $key))->toArray()
                === PatternWeeks::fromArray(self::decode($secondValue, $key))->toArray();
        } catch (InvalidArgumentException|JsonException) {
            return false;
        }
    }

    /**
     * The stored form, for toArray() and JSON.
     *
     * @param  array<string, mixed>  $attributes
     * @return list<mixed>|null
     */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        return $value instanceof PatternWeeks ? $value->toArray() : null;
    }

    /**
     * @return array<mixed>
     */
    private static function decode(mixed $value, string $key): array
    {
        $decoded = is_string($value) ? json_decode($value, true, flags: JSON_THROW_ON_ERROR) : null;

        if (! is_array($decoded)) {
            throw new InvalidArgumentException(sprintf('The stored %s must be a JSON array of weeks.', $key));
        }

        return $decoded;
    }
}
