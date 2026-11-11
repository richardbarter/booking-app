<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\FindsBusinessAndService;
use App\Exceptions\ServiceNotBookable;
use App\Models\Business;
use App\Models\Service;
use App\Scheduling\DayAvailability;
use App\Scheduling\Slot;
use App\Scheduling\TimeRange;
use App\Services\AvailabilityService;
use App\Services\CurrentBusiness;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Prints a day's working hours, free gaps and offered times, with each
 * time's waste score and the day's stranded and fragmented time (06), to
 * try the engine before the playground and to debug it later. It only
 * reads, and prints no customer details or time off reasons, so it's safe
 * to run in production over fly ssh console. It uses the real time, so
 * times today inside the minimum notice aren't shown.
 */
#[Signature('availability:show
    {business : The business ID}
    {service? : The service ID. Leave it out to list the business\'s services}
    {--date= : The date, as YYYY-MM-DD. Defaults to today in the business\'s time zone}')]
#[Description('Show the times the engine offers for a service on a date')]
class ShowAvailability extends Command
{
    use FindsBusinessAndService;

    private const int TIMES_PER_LINE = 12;

    public function handle(CurrentBusiness $currentBusiness, AvailabilityService $availability): int
    {
        $business = $this->findBusiness();

        if ($business === null) {
            return self::FAILURE;
        }

        return $currentBusiness->run($business, function () use ($business, $availability): int {
            if ($this->argument('service') === null) {
                $this->listServices($business);

                return self::SUCCESS;
            }

            return $this->showDay($business, $availability);
        });
    }

    private function listServices(Business $business): void
    {
        $this->line("{$business->name}, business {$business->id}");
        $this->table(
            ['ID', 'Name', 'Length', 'Buffer before', 'Buffer after', 'Active'],
            Service::query()->orderBy('id')->get()->map(fn (Service $service): array => [
                $service->id,
                $service->name,
                "{$service->duration_minutes} min",
                "{$service->buffer_before_minutes} min",
                "{$service->buffer_after_minutes} min",
                $service->is_active ? 'Yes' : 'No',
            ])->all(),
        );
    }

    private function showDay(Business $business, AvailabilityService $availability): int
    {
        $service = $this->findService($business);

        if ($service === null) {
            return self::FAILURE;
        }

        $date = $this->dateFromOption($business);

        if ($date === null) {
            $this->error(sprintf('The date must be a real date written as YYYY-MM-DD, got "%s".', $this->option('date')));

            return self::FAILURE;
        }

        try {
            $days = $availability->forService($business, $service, $date, $date)->days;
        } catch (ServiceNotBookable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line("{$business->name}, business {$business->id}");
        $this->line("{$service->name}, service {$service->id}: {$this->describeTimes($service)}");

        foreach ($days as $day) {
            $this->printDay($day, $business->timezone);
        }

        return self::SUCCESS;
    }

    private function printDay(DayAvailability $day, string $timezone): void
    {
        $this->line(CarbonImmutable::parse($day->date, $timezone)->format('l j F Y').", {$timezone}");
        $this->newLine();
        $this->line('Working hours  '.$this->formatRanges($day->workingTimes, $day->date, $timezone));
        $this->line('Free gaps      '.$this->formatRanges($day->freeGaps, $day->date, $timezone));
        $this->line('Stranded       '.$this->formatGaps($day->gapSummary->stranded, $day->gapSummary->strandedMinutes(), $day->date, $timezone));
        $this->line('Fragmented     '.$this->formatGaps($day->gapSummary->fragmented, $day->gapSummary->fragmentedMinutes(), $day->date, $timezone));
        $this->line('Offered times  '.count($day->slots));

        $times = array_map(fn (Slot $slot): string => $slot->startsAt->setTimezone($timezone)->format('H:i'), $day->slots);

        foreach (array_chunk($times, self::TIMES_PER_LINE) as $line) {
            $this->line('  '.implode(' ', $line));
        }

        if ($day->slots !== []) {
            $this->newLine();
            $this->table(
                ['Time', 'Extra waste', 'Waste', 'Stranded', 'Lost room', 'Split'],
                array_map(fn (Slot $slot): array => [
                    $slot->startsAt->setTimezone($timezone)->format('H:i'),
                    $slot->waste->extraMinutes,
                    $slot->waste->minutes,
                    $slot->waste->strandedMinutes,
                    $slot->waste->lostRoomMinutes,
                    $slot->waste->splitMinutes,
                ], $day->slots),
            );
        }
    }

    /**
     * The day's stranded or fragmented gaps, such as "15 min: 09:00–09:15".
     *
     * @param  list<TimeRange>  $gaps
     */
    private function formatGaps(array $gaps, int $minutes, string $date, string $timezone): string
    {
        return $gaps === [] ? 'none' : "{$minutes} min: {$this->formatRanges($gaps, $date, $timezone)}";
    }

    private function describeTimes(Service $service): string
    {
        $buffers = $service->buffer_before_minutes === 0 && $service->buffer_after_minutes === 0
            ? 'no buffers'
            : "{$service->buffer_before_minutes} minutes before and {$service->buffer_after_minutes} after";

        return "{$service->duration_minutes} minutes, {$buffers}";
    }

    /**
     * Ranges in local time, such as "09:00–13:00, 14:00–17:30". A range
     * ending at the next date's first moment ends at 24:00, and a time
     * part-way through a minute shows its seconds, such as 10:00:30, so a
     * gap never looks longer than it is.
     *
     * @param  list<TimeRange>  $ranges
     */
    private function formatRanges(array $ranges, string $date, string $timezone): string
    {
        if ($ranges === []) {
            return 'none';
        }

        return implode(', ', array_map(function (TimeRange $range) use ($date, $timezone): string {
            $end = $range->end->setTimezone($timezone);

            return $this->formatTime($range->start->setTimezone($timezone)).'–'
                .($end->format('Y-m-d') > $date ? '24:00' : $this->formatTime($end));
        }, $ranges));
    }

    private function formatTime(CarbonImmutable $time): string
    {
        return $time->format($time->second === 0 ? 'H:i' : 'H:i:s');
    }

    /**
     * The --date option, or today in the business's time zone. Null when
     * the option isn't a real YYYY-MM-DD date.
     */
    private function dateFromOption(Business $business): ?CarbonImmutable
    {
        $option = $this->option('date');

        if ($option === null) {
            return now($business->timezone)->toImmutable()->startOfDay();
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $option);

        if ($date === false || $date->format('Y-m-d') !== $option) {
            return null;
        }

        return CarbonImmutable::instance($date);
    }
}
