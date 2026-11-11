<?php

namespace App\Scheduling;

use App\Scheduling\Hours\WorkingHours;

/**
 * One practitioner's diary: their working hours, the services they offer,
 * and the time within the hours that can't be booked. The waste score
 * keeps room for the offered services (06). Blocked time is time off, and
 * from 05 each booking with its buffers. It may overlap itself and run
 * past the dates being searched.
 */
final readonly class Diary
{
    /**
     * @param  list<OfferedService>  $offeredServices  in v1 every active service, and with staff the services the practitioner offers. SlotFinder only searches for one of them
     * @param  list<TimeRange>  $blockedTimes  in any order
     */
    public function __construct(
        public WorkingHours $workingHours,
        public array $offeredServices,
        public array $blockedTimes = [],
    ) {}
}
