<?php

namespace App\Console\Commands;

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
 * Prints a day's working hours, free gaps and offered times, to try the
 * engine before the playground and to debug it later. It only reads, and
 * prints no customer details or time off reasons, so it's safe to run in
 * production over fly ssh console. It uses the real time, so times today
 * inside the minimum notice aren't shown.
 */
#[Signature('availability:show
    {business : The business ID}
    {service? : The service ID. Leave it out to list the business\'s services}
    {--date= : The date, as YYYY-MM-DD. Defaults to today in the business\'s time zone}')]
#[Description('Show the times the engine offers for a service on a date')]
class ShowAvailability extends Command
{
    private const int TIMES_PER_LINE = 12;

    public function handle(CurrentBusiness $currentBusiness, AvailabilityService $availability): int
    {
        $businessId = $this->id($this->argument('business'));
        $business = $businessId === null ? null : Business::query()->find($businessId);

        if ($business === null) {
            $this->error(sprintf('There\'s no business with ID %s.', $this->argument('business')));

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
        $serviceId = $this->id($this->argument('service'));
        $service = $serviceId === null ? null : Service::query()->find($serviceId);

        if ($service === null) {
            $this->error(sprintf('Business %d has no service with ID %s.', $business->id, $this->argument('service')));

            return self::FAILURE;
        }

        $date = $this->date($business);

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
        $this->line('Working hours  '.$this->ranges($day->workingTimes, $day->date, $timezone));
        $this->line('Free gaps      '.$this->ranges($day->freeGaps, $day->date, $timezone));
        $this->line('Offered times  '.count($day->slots));

        $times = array_map(fn (Slot $slot): string => $slot->startsAt->setTimezone($timezone)->format('H:i'), $day->slots);

        foreach (array_chunk($times, self::TIMES_PER_LINE) as $line) {
            $this->line('  '.implode(' ', $line));
        }
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
     * ending at the next date's first moment ends at 24:00.
     *
     * @param  list<TimeRange>  $ranges
     */
    private function ranges(array $ranges, string $date, string $timezone): string
    {
        if ($ranges === []) {
            return 'none';
        }

        return implode(', ', array_map(function (TimeRange $range) use ($date, $timezone): string {
            $end = $range->end->setTimezone($timezone);

            return $range->start->setTimezone($timezone)->format('H:i').'–'.($end->format('Y-m-d') > $date ? '24:00' : $end->format('H:i'));
        }, $ranges));
    }

    /**
     * The --date option, or today in the business's time zone. Null when
     * the option isn't a real YYYY-MM-DD date.
     */
    private function date(Business $business): ?CarbonImmutable
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

    /**
     * A database ID from an argument, or null if it isn't a whole number
     * of 1 or more.
     */
    private function id(?string $argument): ?int
    {
        $id = filter_var($argument, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }
}
