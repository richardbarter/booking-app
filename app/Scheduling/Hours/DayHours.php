<?php

namespace App\Scheduling\Hours;

use InvalidArgumentException;

/**
 * One day's working hours: time ranges in order, which never overlap or
 * touch. The gap between two ranges is a break. No ranges means the
 * practitioner isn't working.
 */
final readonly class DayHours
{
    /**
     * @var list<LocalTimeRange>
     */
    private array $ranges;

    /**
     * @param  list<LocalTimeRange>  $ranges  in any order
     */
    public function __construct(array $ranges = [])
    {
        usort($ranges, fn (LocalTimeRange $first, LocalTimeRange $second): int => $first->startMinute <=> $second->startMinute);

        for ($index = 1; $index < count($ranges); $index++) {
            $previous = $ranges[$index - 1];
            $current = $ranges[$index];

            if ($current->startMinute <= $previous->endMinute) {
                throw new InvalidArgumentException(sprintf(
                    'A day\'s time ranges can\'t overlap or touch, got %s and %s.',
                    implode('–', $previous->toArray()),
                    implode('–', $current->toArray()),
                ));
            }
        }

        $this->ranges = $ranges;
    }

    /**
     * Build a day from its stored form, such as [["09:00", "13:00"], ["14:00", "17:30"]].
     *
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        if (! array_is_list($data)) {
            throw new InvalidArgumentException('A day\'s hours must be a list of time ranges.');
        }

        $ranges = [];

        foreach ($data as $range) {
            if (! is_array($range) || ! array_is_list($range) || count($range) !== 2
                || ! is_string($range[0]) || ! is_string($range[1])) {
                throw new InvalidArgumentException('Each time range must be a pair of times, such as ["09:00", "13:00"].');
            }

            $ranges[] = LocalTimeRange::fromStrings($range[0], $range[1]);
        }

        return new self($ranges);
    }

    /**
     * @return list<LocalTimeRange>
     */
    public function ranges(): array
    {
        return $this->ranges;
    }

    public function isEmpty(): bool
    {
        return $this->ranges === [];
    }

    /**
     * @return list<array{string, string}>
     */
    public function toArray(): array
    {
        return array_map(fn (LocalTimeRange $range): array => $range->toArray(), $this->ranges);
    }
}
