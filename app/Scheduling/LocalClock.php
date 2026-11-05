<?php

namespace App\Scheduling;

use App\Scheduling\Hours\DayHours;
use App\Scheduling\Hours\LocalTimeRange;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Turns local clock times on a date into real instants in one time zone.
 *
 * On the nights the clocks change, some clock times are skipped and others
 * happen twice. A local time on a date means the first moment that day when
 * the clock shows that time or later:
 * - a skipped time means the moment the clocks change
 * - a repeated time means its first occurrence
 * - 24:00 means the first moment of the next date
 *
 * PHP follows no single rule here. It moves a skipped time forward by the
 * size of the jump, and gives either occurrence of a repeated time depending
 * on the method used. So this works the rule out from the zone's
 * transitions. It counts in Unix timestamps internally, and returns
 * instants in UTC.
 */
final readonly class LocalClock
{
    private const int SECONDS_IN_MINUTE = 60;

    private const int SECONDS_IN_DAY = 86400;

    /**
     * How far either side of a date's midnight to read the zone's
     * transitions. Offsets are within 14 hours of UTC, so two days covers
     * every moment of the date.
     */
    private const int TRANSITION_WINDOW_SECONDS = 2 * self::SECONDS_IN_DAY;

    /**
     * The zone must be a named one, such as Europe/London, since its
     * transitions say when the clocks change. Abbreviations such as BST and
     * fixed offsets such as +05:45 have none.
     */
    public function __construct(public DateTimeZone $timezone)
    {
        if (! in_array($timezone->getName(), DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new InvalidArgumentException(sprintf(
                'The time zone must be a named zone such as Europe/London, got %s.',
                $timezone->getName(),
            ));
        }
    }

    /**
     * From the date's first moment to the next date's first moment.
     */
    public function day(CarbonInterface $date): TimeRange
    {
        $midnight = self::midnightOf($date);
        $periods = $this->periodsAround($midnight);

        return new TimeRange(
            self::instant(self::firstMomentFrom($midnight, $periods)),
            self::instant(self::firstMomentFrom($midnight + self::SECONDS_IN_DAY, $periods)),
        );
    }

    /**
     * The day's working hours as real times, in time order. A range wholly
     * inside time the clocks skip has no time in it, and is dropped. Ranges
     * that the clocks bring together, such as either side of a break inside
     * the skipped hour, become one.
     *
     * @return list<TimeRange>
     */
    public function workingTimes(CarbonInterface $date, DayHours $hours): array
    {
        $midnight = self::midnightOf($date);
        $periods = $this->periodsAround($midnight);
        $times = [];

        foreach ($hours->ranges() as $range) {
            $start = self::firstMomentFrom($midnight + $range->startMinute * self::SECONDS_IN_MINUTE, $periods);
            $end = self::firstMomentFrom($midnight + $range->endMinute * self::SECONDS_IN_MINUTE, $periods);
            $last = count($times) - 1;

            if ($start === $end) {
                continue;
            }

            if ($last >= 0 && $times[$last][1] === $start) {
                $times[$last][1] = $end;
            } else {
                $times[] = [$start, $end];
            }
        }

        return array_map(
            fn (array $time): TimeRange => new TimeRange(self::instant($time[0]), self::instant($time[1])),
            $times,
        );
    }

    /**
     * The moment a start time on the date means, or null when the clocks
     * skip it. A repeated time gives its first occurrence only, so it's
     * offered once.
     */
    public function startTimeOn(CarbonInterface $date, int $minuteOfDay): ?CarbonImmutable
    {
        if ($minuteOfDay < 0 || $minuteOfDay >= LocalTimeRange::MINUTES_IN_DAY) {
            throw new InvalidArgumentException(sprintf(
                'A start time must be from 0 to %d minutes after midnight, got %d.',
                LocalTimeRange::MINUTES_IN_DAY - 1,
                $minuteOfDay,
            ));
        }

        $wall = self::midnightOf($date) + $minuteOfDay * self::SECONDS_IN_MINUTE;
        $moment = self::exactMoment($wall, $this->periodsAround($wall));

        return $moment === null ? null : self::instant($moment);
    }

    /**
     * The date's start times: every clock time a whole number of intervals
     * after midnight, as startTimeOn() gives them, in time order.
     *
     * @return list<CarbonImmutable>
     */
    public function startTimesOn(CarbonInterface $date, int $intervalMinutes): array
    {
        if ($intervalMinutes < 1) {
            throw new InvalidArgumentException(sprintf('An interval must be at least 1 minute, got %d.', $intervalMinutes));
        }

        $midnight = self::midnightOf($date);
        $periods = $this->periodsAround($midnight);
        $startTimes = [];

        for ($minute = 0; $minute < LocalTimeRange::MINUTES_IN_DAY; $minute += $intervalMinutes) {
            $moment = self::exactMoment($midnight + $minute * self::SECONDS_IN_MINUTE, $periods);

            if ($moment !== null) {
                $startTimes[] = self::instant($moment);
            }
        }

        return $startTimes;
    }

    /**
     * The local date of an instant, as Y-m-d.
     */
    public function dateOf(CarbonInterface $instant): string
    {
        return $instant->toImmutable()->setTimezone($this->timezone)->format('Y-m-d');
    }

    /**
     * The zone's offsets around a moment, as [from, until, offset] in
     * seconds, in time order. The first starts at the beginning of the
     * window, and the last runs on with no end.
     *
     * @return list<array{int, int, int}>
     */
    private function periodsAround(int $moment): array
    {
        $transitions = $this->timezone->getTransitions(
            $moment - self::TRANSITION_WINDOW_SECONDS,
            $moment + self::TRANSITION_WINDOW_SECONDS,
        );
        $periods = [];

        foreach ($transitions as $index => $transition) {
            $periods[] = [$transition['ts'], $transitions[$index + 1]['ts'] ?? PHP_INT_MAX, $transition['offset']];
        }

        return $periods;
    }

    /**
     * The first moment when the clock shows the wall time or later. The wall
     * time is the local date and time counted as if it were UTC.
     *
     * @param  list<array{int, int, int}>  $periods
     */
    private static function firstMomentFrom(int $wall, array $periods): int
    {
        $first = PHP_INT_MAX;

        foreach ($periods as [$from, $until, $offset]) {
            $moment = max($from, $wall - $offset);

            if ($moment < $until) {
                $first = min($first, $moment);
            }
        }

        return $first;
    }

    /**
     * The first moment when the clock shows exactly the wall time, or null
     * when the clocks skip it.
     *
     * @param  list<array{int, int, int}>  $periods
     */
    private static function exactMoment(int $wall, array $periods): ?int
    {
        foreach ($periods as [$from, $until, $offset]) {
            $moment = $wall - $offset;

            if ($from <= $moment && $moment < $until) {
                return $moment;
            }
        }

        return null;
    }

    /**
     * The date's midnight as a wall time.
     */
    private static function midnightOf(CarbonInterface $date): int
    {
        return (new DateTimeImmutable($date->format('Y-m-d'), new DateTimeZone('UTC')))->getTimestamp();
    }

    private static function instant(int $timestamp): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestamp($timestamp, 'UTC');
    }
}
