<?php

namespace App\Services;

use App\Exceptions\MissingCurrentBusiness;
use App\Exceptions\ServiceNotBookable;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Practitioner;
use App\Models\Service;
use App\Models\TimeOff;
use App\Models\WorkingPattern;
use App\Scheduling\Availability;
use App\Scheduling\BookingSettings;
use App\Scheduling\DateRange;
use App\Scheduling\Diary;
use App\Scheduling\Hours\WorkingHours;
use App\Scheduling\LocalClock;
use App\Scheduling\ServiceTimes;
use App\Scheduling\SlotFinder;
use App\Scheduling\TimeRange;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use LogicException;

/**
 * Loads what the engine needs for a business's practitioner and runs it.
 * It's the only part of the engine that reads the database: five queries
 * whatever the range.
 *
 * In v1 the practitioner offers every active service, at the service's
 * own length and buffers. That rule lives here, so the practitioner's own
 * services can replace it when there are staff.
 */
class AvailabilityService
{
    public function __construct(
        private CurrentBusiness $currentBusiness,
        private SlotFinder $slotFinder,
    ) {}

    /**
     * The service's slots on each date from $from to $to, both included,
     * in the business's time zone. Only the calendar date of each is read.
     *
     * @throws MissingCurrentBusiness when no current business is set
     * @throws LogicException when the current business is another one
     * @throws InvalidArgumentException when the service is another business's, or the dates are invalid
     * @throws ServiceNotBookable when the service isn't active
     */
    public function forService(Business $business, Service $service, CarbonInterface $from, CarbonInterface $to): Availability
    {
        $this->ensureBookable($business, $service);

        $dates = DateRange::between($from, $to);
        $settings = new BookingSettings(
            new DateTimeZone($business->timezone),
            $business->slot_interval_minutes,
            $business->min_notice_minutes,
            $business->max_advance_days,
        );
        $practitioner = $business->practitioners()->sole();
        [$rangeStart, $rangeEnd] = $this->range(new LocalClock($settings->timezone), $dates);

        return $this->slotFinder->find(
            new Diary(
                $this->workingHours($practitioner, $dates),
                [
                    ...$this->timeOff($practitioner, $rangeStart, $rangeEnd),
                    ...$this->bookings($practitioner, $rangeStart, $rangeEnd),
                ],
            ),
            new ServiceTimes($service->duration_minutes, $service->buffer_before_minutes, $service->buffer_after_minutes),
            $settings,
            $dates,
            now(),
        );
    }

    private function ensureBookable(Business $business, Service $service): void
    {
        $this->currentBusiness->ensureIs($business);

        if ($service->business_id !== $business->id) {
            throw new InvalidArgumentException(sprintf(
                'Service %d belongs to another business, not business %d.',
                $service->id,
                $business->id,
            ));
        }

        if (! $service->is_active) {
            throw new ServiceNotBookable($service->id);
        }
    }

    /**
     * The patterns in force during the dates, and the dates' edited days.
     */
    private function workingHours(Practitioner $practitioner, DateRange $dates): WorkingHours
    {
        $patterns = $practitioner->workingPatterns()
            ->where('starts_on', '<=', $dates->last)
            ->where(fn (Builder $query): Builder => $query->whereNull('ends_on')->orWhere('ends_on', '>=', $dates->first))
            ->get();
        $editedDays = $practitioner->editedDays()
            ->whereBetween('date', [$dates->first, $dates->last])
            ->get();

        $hours = [];

        foreach ($editedDays as $editedDay) {
            $hours[$editedDay->date->format('Y-m-d')] = $editedDay->hours;
        }

        return new WorkingHours(
            array_values(array_map(fn (WorkingPattern $pattern) => $pattern->toRepeatingPattern(), $patterns->all())),
            $hours,
        );
    }

    /**
     * From the first date's first moment to the end of the last, in the
     * business's time zone, for the time off and bookings queries. Both
     * ends are formatted with their offset, as AsUtcDateTime writes them,
     * so the queries don't depend on the connection's time zone: Laravel
     * would bind a Carbon without one.
     *
     * @return array{string, string}
     */
    private function range(LocalClock $clock, DateRange $dates): array
    {
        return [
            $clock->day(CarbonImmutable::parse($dates->first, 'UTC'))->start->format('Y-m-d H:i:sP'),
            $clock->day(CarbonImmutable::parse($dates->last, 'UTC'))->end->format('Y-m-d H:i:sP'),
        ];
    }

    /**
     * The time off overlapping the range. Only the start and end are read:
     * the reason never reaches the engine.
     *
     * @return list<TimeRange>
     */
    private function timeOff(Practitioner $practitioner, string $rangeStart, string $rangeEnd): array
    {
        $timeOff = $practitioner->timeOff()
            ->where('starts_at', '<', $rangeEnd)
            ->where('ends_at', '>', $rangeStart)
            ->get(['starts_at', 'ends_at']);

        return array_values(array_map(fn (TimeOff $period): TimeRange => new TimeRange($period->starts_at, $period->ends_at), $timeOff->all()));
    }

    /**
     * The bookings that block time overlapping the range, as their blocked
     * time, buffers included. The range test and active() are written as
     * the exclusion constraint is, so its index serves this query. Only the
     * blocked time is read: no customer details reach the engine.
     *
     * @return list<TimeRange>
     */
    private function bookings(Practitioner $practitioner, string $rangeStart, string $rangeEnd): array
    {
        $bookings = $practitioner->bookings()
            ->active()
            ->whereRaw("tstzrange(blocked_from, blocked_until, '[)') && tstzrange(?, ?, '[)')", [$rangeStart, $rangeEnd])
            ->get(['blocked_from', 'blocked_until']);

        return array_values(array_map(fn (Booking $booking): TimeRange => new TimeRange($booking->blocked_from, $booking->blocked_until), $bookings->all()));
    }
}
