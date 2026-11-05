<?php

namespace App\Scheduling;

/**
 * The engine's answer for a range of dates: one day for each date, in order.
 */
final readonly class Availability
{
    /**
     * @param  list<DayAvailability>  $days
     */
    public function __construct(public array $days) {}

    /**
     * The day for a Y-m-d date, or null if the date isn't in the range.
     */
    public function day(string $date): ?DayAvailability
    {
        foreach ($this->days as $day) {
            if ($day->date === $date) {
                return $day;
            }
        }

        return null;
    }
}
