<?php

namespace App\Scheduling;

use Carbon\CarbonInterface;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * The dates the engine works out slots for, from first to last, both
 * included. Only the calendar date of each date given is read, in its own
 * time zone, and dates are held as Y-m-d strings.
 */
final readonly class DateRange
{
    /**
     * The longest booking window (365 days) plus today.
     */
    public const int MAX_DATES = 366;

    private const int SECONDS_IN_DAY = 86400;

    private function __construct(
        public string $first,
        public string $last,
    ) {}

    public static function between(CarbonInterface $from, CarbonInterface $to): self
    {
        $first = $from->format('Y-m-d');
        $last = $to->format('Y-m-d');
        $count = self::dayNumber($last) - self::dayNumber($first) + 1;

        if ($count < 1) {
            throw new InvalidArgumentException(sprintf(
                'A date range can\'t end before it starts, got %s to %s.',
                $first,
                $last,
            ));
        }

        if ($count > self::MAX_DATES) {
            throw new InvalidArgumentException(sprintf(
                'A date range can have at most %d dates, got %d from %s to %s.',
                self::MAX_DATES,
                $count,
                $first,
                $last,
            ));
        }

        return new self($first, $last);
    }

    /**
     * @return list<string>
     */
    public function dates(): array
    {
        $dates = [];

        for ($day = self::dayNumber($this->first); $day <= self::dayNumber($this->last); $day++) {
            $dates[] = gmdate('Y-m-d', $day * self::SECONDS_IN_DAY);
        }

        return $dates;
    }

    /**
     * Counts whole days from a Y-m-d date, at midnight UTC, so a clock
     * change in any time zone can't add or lose a day.
     */
    private static function dayNumber(string $date): int
    {
        return intdiv((new DateTimeImmutable($date, new DateTimeZone('UTC')))->getTimestamp(), self::SECONDS_IN_DAY);
    }
}
