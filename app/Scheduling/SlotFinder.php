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
    private const int SECONDS_IN_MINUTE = 60;

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
     * Start times are checked in whole seconds, since a day has up to 144
     * of them and Carbon objects are only made for the ones that fit.
     *
     * @param  list<CarbonImmutable>  $startTimes
     * @param  list<TimeRange>  $freeGaps
     * @return list<Slot>
     */
    private static function slots(array $startTimes, array $freeGaps, ServiceTimes $service, BookingWindow $window): array
    {
        $gaps = self::inWholeSeconds($freeGaps);
        $slots = [];

        foreach ($startTimes as $startsAt) {
            $start = $startsAt->getTimestamp();
            $gap = self::gapContaining(
                $gaps,
                $start - $service->bufferBeforeMinutes * self::SECONDS_IN_MINUTE,
                $start + ($service->durationMinutes + $service->bufferAfterMinutes) * self::SECONDS_IN_MINUTE,
            );

            if ($gap !== null && $window->allows($startsAt)) {
                $endsAt = $startsAt->addMinutes($service->durationMinutes);
                $slots[] = new Slot(
                    $startsAt,
                    $endsAt,
                    $startsAt->subMinutes($service->bufferBeforeMinutes),
                    $endsAt->addMinutes($service->bufferAfterMinutes),
                    $gap,
                );
            }
        }

        return $slots;
    }

    /**
     * Each free gap with its start and end in whole seconds, rounded
     * inwards, so a range of whole seconds fits inside the rounded gap
     * exactly when it fits inside the real one. Blocked time can end
     * part-way through a second.
     *
     * @param  list<TimeRange>  $freeGaps
     * @return list<array{TimeRange, int, int}>
     */
    private static function inWholeSeconds(array $freeGaps): array
    {
        return array_map(fn (TimeRange $gap): array => [
            $gap,
            $gap->start->ceilSecond()->getTimestamp(),
            $gap->end->floorSecond()->getTimestamp(),
        ], $freeGaps);
    }

    /**
     * @param  list<array{TimeRange, int, int}>  $gaps
     */
    private static function gapContaining(array $gaps, int $from, int $until): ?TimeRange
    {
        foreach ($gaps as [$gap, $start, $end]) {
            if ($start <= $from && $until <= $end) {
                return $gap;
            }
        }

        return null;
    }
}
