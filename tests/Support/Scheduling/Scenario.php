<?php

namespace Tests\Support\Scheduling;

use App\Scheduling\BookingSettings;
use App\Scheduling\DateRange;
use App\Scheduling\Diary;
use App\Scheduling\Hours\DayHours;
use App\Scheduling\Hours\RepeatingPattern;
use App\Scheduling\Hours\WorkingHours;
use App\Scheduling\OfferedService;
use App\Scheduling\ServiceTimes;
use App\Scheduling\TimeRange;
use Carbon\CarbonImmutable;

/**
 * One random diary and search for the randomised rule checks, with the
 * seed and number that recreate it.
 */
final readonly class Scenario
{
    /**
     * @param  list<RepeatingPattern>  $patterns
     * @param  array<string, DayHours>  $editedDays
     * @param  list<TimeRange>  $blockedTimes
     * @param  list<OfferedService>  $offeredServices  including the service searched for
     */
    public function __construct(
        public int $seed,
        public int $number,
        public array $patterns,
        public array $editedDays,
        public array $blockedTimes,
        public ServiceTimes $service,
        public BookingSettings $settings,
        public DateRange $dates,
        public CarbonImmutable $now,
        public array $offeredServices,
    ) {}

    public function diary(): Diary
    {
        return new Diary(new WorkingHours($this->patterns, $this->editedDays), $this->offeredServices, $this->blockedTimes);
    }

    /**
     * The scenario as JSON, to print when a rule fails, so it can be turned
     * into a worked example.
     */
    public function toJson(): string
    {
        return json_encode([
            'seed' => $this->seed,
            'scenario' => $this->number,
            'timezone' => $this->settings->timezone->getName(),
            'dates' => [$this->dates->first, $this->dates->last],
            'now' => $this->now->toIso8601String(),
            'slot_interval_minutes' => $this->settings->slotIntervalMinutes,
            'min_notice_minutes' => $this->settings->minNoticeMinutes,
            'max_advance_days' => $this->settings->maxAdvanceDays,
            'service' => [
                'duration_minutes' => $this->service->durationMinutes,
                'buffer_before_minutes' => $this->service->bufferBeforeMinutes,
                'buffer_after_minutes' => $this->service->bufferAfterMinutes,
            ],
            'patterns' => array_map(fn (RepeatingPattern $pattern): array => [
                'starts_on' => $pattern->startsOn,
                'ends_on' => $pattern->endsOn,
                'cycle_starts_on' => $pattern->cycleStartsOn,
                'weeks' => $pattern->weeks->toArray(),
            ], $this->patterns),
            'edited_days' => array_map(fn (DayHours $hours): array => $hours->toArray(), $this->editedDays),
            'blocked_times' => array_map(fn (TimeRange $range): array => [
                $range->start->toIso8601String(),
                $range->end->toIso8601String(),
            ], $this->blockedTimes),
            'offered_services' => array_map(fn (OfferedService $offered): array => [
                'duration_minutes' => $offered->times->durationMinutes,
                'buffer_before_minutes' => $offered->times->bufferBeforeMinutes,
                'buffer_after_minutes' => $offered->times->bufferAfterMinutes,
                'room_weight' => $offered->roomWeight,
            ], $this->offeredServices),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }
}
