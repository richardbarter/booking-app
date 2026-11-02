<?php

namespace App\Scheduling\Hours;

use InvalidArgumentException;

/**
 * One week of a working pattern: the hours for each day from Monday to Sunday.
 */
final readonly class WeekHours
{
    /**
     * The stored form's keys, in ISO weekday order (Monday is 1).
     */
    public const array DAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public function __construct(
        public DayHours $monday,
        public DayHours $tuesday,
        public DayHours $wednesday,
        public DayHours $thursday,
        public DayHours $friday,
        public DayHours $saturday,
        public DayHours $sunday,
    ) {}

    /**
     * Build a week from its stored form, which has exactly the keys mon to sun.
     *
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $keys = array_keys($data);
        sort($keys);
        $expectedKeys = self::DAY_KEYS;
        sort($expectedKeys);

        if ($keys !== $expectedKeys) {
            throw new InvalidArgumentException('A week must have exactly the keys '.implode(', ', self::DAY_KEYS).'.');
        }

        return new self(
            self::dayFrom($data, 'mon'),
            self::dayFrom($data, 'tue'),
            self::dayFrom($data, 'wed'),
            self::dayFrom($data, 'thu'),
            self::dayFrom($data, 'fri'),
            self::dayFrom($data, 'sat'),
            self::dayFrom($data, 'sun'),
        );
    }

    public function forWeekday(int $isoWeekday): DayHours
    {
        return match ($isoWeekday) {
            1 => $this->monday,
            2 => $this->tuesday,
            3 => $this->wednesday,
            4 => $this->thursday,
            5 => $this->friday,
            6 => $this->saturday,
            7 => $this->sunday,
            default => throw new InvalidArgumentException(sprintf(
                'An ISO weekday is from 1 (Monday) to 7 (Sunday), got %d.',
                $isoWeekday,
            )),
        };
    }

    /**
     * @return array<string, list<array{string, string}>>
     */
    public function toArray(): array
    {
        $week = [];

        foreach (self::DAY_KEYS as $index => $key) {
            $week[$key] = $this->forWeekday($index + 1)->toArray();
        }

        return $week;
    }

    /**
     * @param  array<mixed>  $data
     */
    private static function dayFrom(array $data, string $key): DayHours
    {
        if (! is_array($data[$key])) {
            throw new InvalidArgumentException(sprintf('The hours for "%s" must be a list of time ranges.', $key));
        }

        return DayHours::fromArray($data[$key]);
    }
}
