<?php

namespace App\Scheduling;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The availability engine. For each date, it:
 * 1. gets the practitioner's working hours on that date
 * 2. turns them into real times in the business's time zone
 * 3. takes away blocked time, leaving the free gaps
 * 4. lists the start times on the slot interval where the service, with
 *    its buffers, fits inside one free gap
 * 5. keeps the start times inside the booking window
 *
 * It's given everything it works on, the current time included, and reads
 * no database or clock.
 */
final readonly class SlotFinder
{
    public function find(
        Diary $diary,
        ServiceTimes $service,
        BookingSettings $settings,
        DateRange $dates,
        CarbonInterface $now,
    ): Availability {
        $clock = new LocalClock($settings->timezone);
        $window = new BookingWindow($settings, $now);
        $days = [];

        foreach ($dates->dates() as $date) {
            $day = CarbonImmutable::parse($date, 'UTC');
            $workingTimes = $clock->workingTimes($day, $diary->workingHours->on($day));
            $freeGaps = FreeGaps::between($workingTimes, $diary->blockedTimes);
            $slots = $freeGaps === []
                ? []
                : self::slots($clock->startTimesOn($day, $settings->slotIntervalMinutes), $freeGaps, $service, $window);

            $days[] = new DayAvailability($date, $workingTimes, $freeGaps, $slots);
        }

        return new Availability($days);
    }

    /**
     * @param  list<CarbonImmutable>  $startTimes
     * @param  list<TimeRange>  $freeGaps
     * @return list<Slot>
     */
    private static function slots(array $startTimes, array $freeGaps, ServiceTimes $service, BookingWindow $window): array
    {
        $slots = [];

        foreach ($startTimes as $startsAt) {
            $endsAt = $startsAt->addMinutes($service->durationMinutes);
            $blocked = new TimeRange(
                $startsAt->subMinutes($service->bufferBeforeMinutes),
                $endsAt->addMinutes($service->bufferAfterMinutes),
            );
            $gap = self::gapContaining($freeGaps, $blocked);

            if ($gap !== null && $window->allows($startsAt)) {
                $slots[] = new Slot($startsAt, $endsAt, $blocked->start, $blocked->end, $gap);
            }
        }

        return $slots;
    }

    /**
     * @param  list<TimeRange>  $freeGaps
     */
    private static function gapContaining(array $freeGaps, TimeRange $blocked): ?TimeRange
    {
        foreach ($freeGaps as $gap) {
            if ($gap->contains($blocked)) {
                return $gap;
            }
        }

        return null;
    }
}
