<?php

namespace App\Scheduling\Hours;

use InvalidArgumentException;

/**
 * A range of local clock times within one day, such as 09:00 to 13:00, held
 * as minutes after midnight. It has no date or time zone: the engine turns
 * it into real times on a date.
 */
final readonly class LocalTimeRange
{
    public const int MINUTES_IN_DAY = 1440;

    public const int MINUTE_STEP = 5;

    public function __construct(
        public int $startMinute,
        public int $endMinute,
    ) {
        self::assertValidMinute($startMinute);
        self::assertValidMinute($endMinute);

        if ($startMinute >= $endMinute) {
            throw new InvalidArgumentException(sprintf(
                'A time range must start before it ends, got %s to %s.',
                self::format($startMinute),
                self::format($endMinute),
            ));
        }
    }

    /**
     * Build a range from two HH:MM times, such as "09:00" and "13:00".
     */
    public static function fromStrings(string $start, string $end): self
    {
        return new self(self::parse($start), self::parse($end));
    }

    /**
     * @return array{string, string}
     */
    public function toArray(): array
    {
        return [self::format($this->startMinute), self::format($this->endMinute)];
    }

    private static function parse(string $time): int
    {
        if (preg_match('/^(\d{2}):([0-5]\d)$/D', $time, $matches) !== 1) {
            throw new InvalidArgumentException(sprintf('A time must be written as HH:MM, got "%s".', $time));
        }

        return (int) $matches[1] * 60 + (int) $matches[2];
    }

    private static function assertValidMinute(int $minute): void
    {
        if ($minute < 0 || $minute > self::MINUTES_IN_DAY) {
            throw new InvalidArgumentException(sprintf(
                'A time must be from 00:00 to 24:00, got %d minutes after midnight.',
                $minute,
            ));
        }

        if ($minute % self::MINUTE_STEP !== 0) {
            throw new InvalidArgumentException(sprintf(
                'A time must be on a %d-minute step, got %s.',
                self::MINUTE_STEP,
                self::format($minute),
            ));
        }
    }

    private static function format(int $minute): string
    {
        return sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
    }
}
