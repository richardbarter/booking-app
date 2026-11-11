<?php

namespace App\Scheduling;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * The availability engine. For each date, it:
 * 1. gets the practitioner's working hours on that date
 * 2. turns them into real times in the business's time zone
 * 3. takes away blocked time, leaving the free gaps
 * 4. lists the start times on the slot interval where the service, with
 *    its buffers, fits inside one free gap
 * 5. keeps the start times inside the booking window
 * 6. scores each one by how much room it would cost the bookings still to
 *    come, and finds the day's stranded and fragmented gaps (WasteScorer)
 *
 * It's given everything it works on, the current time included, and reads
 * no database or clock.
 */
final readonly class SlotFinder
{
    private const int SECONDS_IN_MINUTE = 60;

    /**
     * @throws InvalidArgumentException when the service isn't one of the diary's offered services, since slots are scored against them
     */
    public function find(
        Diary $diary,
        ServiceTimes $service,
        BookingSettings $settings,
        DateRange $dates,
        CarbonInterface $now,
    ): Availability {
        self::ensureOffered($diary, $service);

        $clock = new LocalClock($settings->timezone);
        $window = new BookingWindow($settings, $now);
        $scorer = new WasteScorer($diary->offeredServices);
        $days = [];

        foreach ($dates->dates() as $date) {
            $day = CarbonImmutable::parse($date, 'UTC');
            $workingTimes = $clock->workingTimes($day, $diary->workingHours->on($day));
            $freeGaps = FreeGaps::between($workingTimes, $diary->blockedTimes);
            $startTimes = $freeGaps === [] ? [] : $clock->startTimesOn($day, $settings->slotIntervalMinutes);
            $fit = new ServiceFit($startTimes);

            $days[] = new DayAvailability(
                $date,
                $workingTimes,
                $freeGaps,
                self::slots($startTimes, $freeGaps, $service, $window, $scorer, $fit),
                $scorer->gapSummary($freeGaps, $fit),
            );
        }

        return new Availability($days);
    }

    private static function ensureOffered(Diary $diary, ServiceTimes $service): void
    {
        foreach ($diary->offeredServices as $offered) {
            if ($offered->times->equals($service)) {
                return;
            }
        }

        throw new InvalidArgumentException(sprintf(
            'The service searched for (%d minutes, with %d before and %d after) must be one of the diary\'s offered services.',
            $service->durationMinutes,
            $service->bufferBeforeMinutes,
            $service->bufferAfterMinutes,
        ));
    }

    /**
     * Start times are checked in whole seconds, since a day has up to 144
     * of them, and the slots' other times are only worked out with Carbon
     * for the ones that fit. Each gap's offered slots are scored together,
     * so extra waste compares the offered slots in the same gap.
     *
     * @param  list<CarbonImmutable>  $startTimes
     * @param  list<TimeRange>  $freeGaps
     * @return list<Slot>
     */
    private static function slots(
        array $startTimes,
        array $freeGaps,
        ServiceTimes $service,
        BookingWindow $window,
        WasteScorer $scorer,
        ServiceFit $fit,
    ): array {
        $slots = [];

        foreach ($freeGaps as $gap) {
            [$gapStart, $gapEnd] = $gap->wholeSecondsInside();
            $offered = [];

            foreach ($startTimes as $startsAt) {
                $start = $startsAt->getTimestamp();
                $blockedFrom = $start - $service->bufferBeforeMinutes * self::SECONDS_IN_MINUTE;
                $blockedUntil = $start + ($service->durationMinutes + $service->bufferAfterMinutes) * self::SECONDS_IN_MINUTE;

                if ($gapStart <= $blockedFrom && $blockedUntil <= $gapEnd && $window->allows($startsAt)) {
                    $offered[] = [$startsAt, [$blockedFrom, $blockedUntil]];
                }
            }

            $scores = $scorer->scores(array_column($offered, 1), $gapStart, $gapEnd, $fit);

            foreach ($offered as $index => [$startsAt]) {
                $endsAt = $startsAt->addMinutes($service->durationMinutes);
                $slots[] = new Slot(
                    $startsAt,
                    $endsAt,
                    $startsAt->subMinutes($service->bufferBeforeMinutes),
                    $endsAt->addMinutes($service->bufferAfterMinutes),
                    $gap,
                    $scores[$index],
                );
            }
        }

        return $slots;
    }
}
