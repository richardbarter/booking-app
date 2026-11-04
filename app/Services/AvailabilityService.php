<?php

namespace App\Services;

use App\Exceptions\MissingCurrentBusiness;
use App\Exceptions\ServiceNotBookable;
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
 * It's the only part of the engine that reads the database: four queries
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

        return $this->slotFinder->find(
            new Diary(
                $this->workingHours($practitioner, $dates),
                $this->timeOff($practitioner, new LocalClock($settings->timezone), $dates),
            ),
            new ServiceTimes($service->duration_minutes, $service->buffer_before_minutes, $service->buffer_after_minutes),
            $settings,
            $dates,
            now(),
        );
    }

    private function ensureBookable(Business $business, Service $service): void
    {
        $current = $this->currentBusiness->get();

        if ($current->isNot($business)) {
            throw new LogicException(sprintf(
                'Availability was asked for business %d, but the current business is %d.',
                $business->id,
                $current->id,
            ));
        }

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
     * The time off overlapping the dates, from the first date's first
     * moment to the end of the last, in the business's time zone. Both
     * are bound with their offset, as AsUtcDateTime writes them, so the
     * query doesn't depend on the connection's time zone: Laravel would
     * bind a Carbon without one. Only the start and end are read: the
     * reason never reaches the engine.
     *
     * @return list<TimeRange>
     */
    private function timeOff(Practitioner $practitioner, LocalClock $clock, DateRange $dates): array
    {
        $rangeStart = $clock->day(CarbonImmutable::parse($dates->first, 'UTC'))->start->format('Y-m-d H:i:sP');
        $rangeEnd = $clock->day(CarbonImmutable::parse($dates->last, 'UTC'))->end->format('Y-m-d H:i:sP');

        $timeOff = $practitioner->timeOff()
            ->where('starts_at', '<', $rangeEnd)
            ->where('ends_at', '>', $rangeStart)
            ->get(['starts_at', 'ends_at']);

        return array_values(array_map(fn (TimeOff $period): TimeRange => new TimeRange($period->starts_at, $period->ends_at), $timeOff->all()));
    }
}
