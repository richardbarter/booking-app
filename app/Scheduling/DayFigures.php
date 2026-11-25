<?php

namespace App\Scheduling;

/**
 * How one day's working time was used, in whole minutes (08). Booked time
 * is the appointments' own, without their buffers. Free time is what's in
 * the day's free gaps, so it leaves out buffers and time off as well as
 * appointments. Stranded and fragmented time are parts of it (GapSummary).
 * The playground shows these for its day, and 14 records them for each day
 * that has ended.
 */
final readonly class DayFigures
{
    private const int PERCENT = 100;

    public function __construct(
        public int $workingMinutes,
        public int $bookedMinutes,
        public int $freeMinutes,
        public int $strandedMinutes,
        public int $fragmentedMinutes,
    ) {}

    /**
     * @param  list<TimeRange>  $appointments  those that start on the day, without their buffers. Each counts its whole length, even outside the working hours
     */
    public static function of(DayAvailability $day, array $appointments): self
    {
        return new self(
            self::minutes($day->workingTimes),
            self::minutes($appointments),
            self::minutes($day->freeGaps),
            $day->gapSummary->strandedMinutes(),
            $day->gapSummary->fragmentedMinutes(),
        );
    }

    /**
     * The efficiency score (overview, 5.6): of the time bookings took up,
     * the percentage that was booked rather than stranded, with halves
     * rounded up. Null on a day with neither, which has nothing to score.
     */
    public function efficiency(): ?int
    {
        $used = $this->bookedMinutes + $this->strandedMinutes;

        if ($used === 0) {
            return null;
        }

        return intdiv(2 * self::PERCENT * $this->bookedMinutes + $used, 2 * $used);
    }

    /**
     * @param  list<TimeRange>  $ranges
     */
    private static function minutes(array $ranges): int
    {
        return array_sum(array_map(fn (TimeRange $range): int => $range->wholeMinutes(), $ranges));
    }
}
