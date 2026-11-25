<?php

namespace App\Scheduling;

use InvalidArgumentException;

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

    /**
     * The least wasteful slot in the same free gap as one of the day's
     * slots, offered or hidden, and the earliest if several tie. It's the
     * one the gap's extra waste is measured from, and strictness never
     * hides it, so it's always among the offered slots.
     *
     * @throws InvalidArgumentException when the slot isn't one of the day's
     */
    public function bestInGapOf(Slot $slot): Slot
    {
        $best = in_array($slot, [...$this->slots, ...$this->hiddenSlots], true)
            ? array_find($this->slots, fn (Slot $other): bool => $other->waste->extraMinutes === 0 && $other->gap->equals($slot->gap))
            : null;

        return $best ?? throw new InvalidArgumentException(sprintf(
            'The slot at %s isn\'t one of those on %s.',
            $slot->startsAt->toIso8601String(),
            $this->date,
        ));
    }
}
