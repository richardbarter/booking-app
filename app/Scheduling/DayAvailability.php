<?php

namespace App\Scheduling;

/**
 * One date's diary as the engine sees it. Only the slots are limited by
 * the booking window, so any date's working times and free gaps can be
 * drawn. Each list is in time order.
 */
final readonly class DayAvailability
{
    /**
     * @param  string  $date  Y-m-d, in the business's time zone
     * @param  list<TimeRange>  $workingTimes
     * @param  list<TimeRange>  $freeGaps  including those too short for the service
     * @param  list<Slot>  $slots
     */
    public function __construct(
        public string $date,
        public array $workingTimes,
        public array $freeGaps,
        public array $slots,
    ) {}
}
