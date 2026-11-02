<?php

namespace App\Scheduling\Hours;

use Carbon\CarbonInterface;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * A working pattern: a cycle of weeks repeated from startsOn until endsOn,
 * or with no end. Its weeks count from cycleStartsOn, the Monday that
 * starts week 1, which can be earlier than startsOn. A pattern that starts
 * mid-week, or resumes after a temporary one, keeps its rhythm that way.
 *
 * Only the calendar date of each date given is read, in its own time zone,
 * so its time and zone never shift a day. Dates are held as Y-m-d strings.
 */
final readonly class RepeatingPattern
{
    public string $startsOn;

    public ?string $endsOn;

    public string $cycleStartsOn;

    public function __construct(
        CarbonInterface $startsOn,
        ?CarbonInterface $endsOn,
        CarbonInterface $cycleStartsOn,
        public PatternWeeks $weeks,
    ) {
        $this->startsOn = $startsOn->format('Y-m-d');
        $this->endsOn = $endsOn?->format('Y-m-d');
        $this->cycleStartsOn = $cycleStartsOn->format('Y-m-d');

        if ($this->endsOn !== null && $this->endsOn < $this->startsOn) {
            throw new InvalidArgumentException(sprintf(
                'A pattern can\'t end before it starts, got %s to %s.',
                $this->startsOn,
                $this->endsOn,
            ));
        }

        if ($cycleStartsOn->isoWeekday() !== 1) {
            throw new InvalidArgumentException(sprintf(
                'A pattern\'s cycle must start on a Monday, got %s.',
                $this->cycleStartsOn,
            ));
        }

        if ($this->cycleStartsOn > $this->startsOn) {
            throw new InvalidArgumentException(sprintf(
                'A pattern\'s cycle can\'t start after the pattern, got %s for a pattern from %s.',
                $this->cycleStartsOn,
                $this->startsOn,
            ));
        }
    }

    /**
     * Whether the pattern is in force on the date: from its first date to
     * its last, both included.
     */
    public function covers(CarbonInterface $date): bool
    {
        $day = $date->format('Y-m-d');

        return $day >= $this->startsOn && ($this->endsOn === null || $day <= $this->endsOn);
    }

    /**
     * The pattern's hours on a date it covers.
     */
    public function hoursOn(CarbonInterface $date): DayHours
    {
        if (! $this->covers($date)) {
            throw new InvalidArgumentException(sprintf(
                'The pattern from %s doesn\'t cover %s.',
                $this->startsOn,
                $date->format('Y-m-d'),
            ));
        }

        $isoWeekday = $date->isoWeekday();
        $daysFromCycleStartToMonday = self::dayNumber($date->format('Y-m-d'))
            - self::dayNumber($this->cycleStartsOn)
            - ($isoWeekday - 1);
        $weekIndex = intdiv($daysFromCycleStartToMonday, 7) % $this->weeks->count();

        return $this->weeks->week($weekIndex)->forWeekday($isoWeekday);
    }

    /**
     * Counts whole days from a Y-m-d date, at midnight UTC, so a clock
     * change in any time zone can't add or lose a day.
     */
    private static function dayNumber(string $date): int
    {
        return intdiv((new DateTimeImmutable($date, new DateTimeZone('UTC')))->getTimestamp(), 86400);
    }
}
