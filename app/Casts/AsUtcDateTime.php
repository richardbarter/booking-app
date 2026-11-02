<?php

namespace App\Casts;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\ComparesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Stores an instant in a timestamptz column as UTC, and reads it back as a
 * CarbonImmutable in UTC. Eloquent's own datetime cast saves a date in
 * whatever zone it carries, so 10:00 London time in July would be stored
 * as 10:00 UTC.
 *
 * Only a Carbon instance can be set, since a string has no zone to convert
 * from. Setting a string throws a TypeError.
 *
 * @implements CastsAttributes<CarbonImmutable, CarbonInterface>
 */
class AsUtcDateTime implements CastsAttributes, ComparesCastableAttributes
{
    /**
     * Eloquent would otherwise keep the object it was given and hand that back,
     * in the caller's time zone, until the model is reloaded. A mutable Carbon
     * changed after it was set would also change what's saved.
     */
    public bool $withoutObjectCaching = true;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return $value === null ? null : self::parse($value, $key);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::instance($value)->utc()->format('Y-m-d H:i:sP');
    }

    /**
     * Two stored values are the same when they're the same instant, whatever
     * offset they were written with.
     */
    public function compare(Model $model, string $key, mixed $firstValue, mixed $secondValue): bool
    {
        if ($firstValue === null || $secondValue === null) {
            return $firstValue === $secondValue;
        }

        return self::parse($firstValue, $key)->equalTo(self::parse($secondValue, $key));
    }

    private static function parse(mixed $value, string $key): CarbonImmutable
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException(sprintf('The stored %s must be a timestamp string.', $key));
        }

        return CarbonImmutable::parse($value)->utc();
    }
}
