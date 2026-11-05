<?php

namespace App\Scheduling;

use App\Scheduling\Hours\WorkingHours;

/**
 * One practitioner's diary: their working hours, and the time within them
 * that can't be booked. Blocked time is time off, and from 05 each
 * booking with its buffers. It may overlap itself and run past the dates
 * being searched.
 */
final readonly class Diary
{
    /**
     * @param  list<TimeRange>  $blockedTimes  in any order
     */
    public function __construct(
        public WorkingHours $workingHours,
        public array $blockedTimes = [],
    ) {}
}
