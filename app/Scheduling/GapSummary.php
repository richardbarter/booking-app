<?php

namespace App\Scheduling;

/**
 * A day's free gaps that its bookings can't use well:
 * - stranded: no offered service fits, whatever its room weight
 * - fragmented: a service fits, but not the longest kind of appointment
 *   with a room weight above 0, the one taking the most diary time
 *
 * Every other gap is usable. The efficiency score (14) counts these for
 * days that have ended.
 */
final readonly class GapSummary
{
    private const int SECONDS_IN_MINUTE = 60;

    /**
     * @param  list<TimeRange>  $stranded  in time order
     * @param  list<TimeRange>  $fragmented  in time order
     */
    public function __construct(
        public array $stranded = [],
        public array $fragmented = [],
    ) {}

    public function strandedMinutes(): int
    {
        return self::minutes($this->stranded);
    }

    public function fragmentedMinutes(): int
    {
        return self::minutes($this->fragmented);
    }

    /**
     * The whole minutes in the gaps, each counted in its whole seconds, so
     * a gap never counts as longer than it is.
     *
     * @param  list<TimeRange>  $gaps
     */
    private static function minutes(array $gaps): int
    {
        $minutes = 0;

        foreach ($gaps as $gap) {
            [$start, $end] = $gap->wholeSecondsInside();
            $minutes += intdiv($end - $start, self::SECONDS_IN_MINUTE);
        }

        return $minutes;
    }
}
