<?php

namespace App\Scheduling;

/**
 * One date's diary as the engine sees it. Only the slots are limited by
 * the booking window, so any date's working times, free gaps and gap
 * summary can be drawn. The slots are the times offered, and the hidden
 * slots the ones strictness hid (07), kept with their waste scores so they
 * can be shown and explained. Together they're every slot that fits inside
 * the booking window. Each list is in time order.
 */
final readonly class DayAvailability
{
    /**
     * @param  string  $date  Y-m-d, in the business's time zone
     * @param  list<TimeRange>  $workingTimes
     * @param  list<TimeRange>  $freeGaps  including those too short for the service
     * @param  list<Slot>  $slots  the times offered
     * @param  list<Slot>  $hiddenSlots  the times strictness hid
     */
    public function __construct(
        public string $date,
        public array $workingTimes,
        public array $freeGaps,
        public array $slots,
        public array $hiddenSlots,
        public GapSummary $gapSummary,
    ) {}
}
