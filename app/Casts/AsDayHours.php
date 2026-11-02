<?php

namespace App\Casts;

use App\Scheduling\Hours\DayHours;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\ComparesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Stores one day of hours as JSON, such as [["09:00", "13:00"]], building
 * DayHours on the way in and out, so invalid hours fail in both directions.
 *
 * @implements CastsAttributes<DayHours, DayHours|array<mixed>>
 */
class AsDayHours implements CastsAttributes, ComparesCastableAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?DayHours
    {
        return $value === null ? null : DayHours::fromArray(self::decode($value, $key));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $hours = $value instanceof DayHours ? $value : DayHours::fromArray($value);

        return json_encode($hours->toArray(), JSON_THROW_ON_ERROR);
    }

    /**
     * Two stored values are the same when they hold the same hours, however
     * the JSON was spaced or ordered.
     */
    public function compare(Model $model, string $key, mixed $firstValue, mixed $secondValue): bool
    {
        if ($firstValue === null || $secondValue === null) {
            return $firstValue === $secondValue;
        }

        return DayHours::fromArray(self::decode($firstValue, $key))->toArray()
            === DayHours::fromArray(self::decode($secondValue, $key))->toArray();
    }

    /**
     * @return array<mixed>
     */
    private static function decode(mixed $value, string $key): array
    {
        $decoded = is_string($value) ? json_decode($value, true, flags: JSON_THROW_ON_ERROR) : null;

        if (! is_array($decoded)) {
            throw new InvalidArgumentException(sprintf('The stored %s must be a JSON array of time ranges.', $key));
        }

        return $decoded;
    }
}
