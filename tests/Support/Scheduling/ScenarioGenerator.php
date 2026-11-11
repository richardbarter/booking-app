<?php

namespace Tests\Support\Scheduling;

use App\Scheduling\BookingSettings;
use App\Scheduling\DateRange;
use App\Scheduling\Hours\DayHours;
use App\Scheduling\Hours\LocalTimeRange;
use App\Scheduling\Hours\PatternWeeks;
use App\Scheduling\Hours\RepeatingPattern;
use App\Scheduling\Hours\WeekHours;
use App\Scheduling\OfferedService;
use App\Scheduling\ServiceTimes;
use App\Scheduling\TimeRange;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Builds random scenarios for the randomised rule checks. Each scenario
 * has its own engine, seeded from the seed and its number, so any one can
 * be recreated on its own, and nothing touches PHP's global random state.
 */
final readonly class ScenarioGenerator
{
    /**
     * Zones with clocks that change at night (London, New York, Sydney,
     * Auckland), by 30 minutes (Lord Howe Island) or at midnight
     * (Santiago), a 45-minute offset (Kathmandu), and zones that never
     * change.
     */
    public const array TIMEZONES = [
        'Europe/London', 'America/New_York', 'Australia/Sydney', 'Pacific/Auckland', 'Asia/Kolkata',
        'Asia/Kathmandu', 'Australia/Lord_Howe', 'America/Santiago', 'UTC',
    ];

    private const string EARLIEST_DATE = '2026-01-01';

    private const string LATEST_DATE = '2028-12-31';

    private const int MINUTES_IN_DAY = 1440;

    public function __construct(private int $seed) {}

    public function scenario(int $number): Scenario
    {
        $random = new Randomizer(new Mt19937(crc32("{$this->seed}:{$number}")));
        $zone = new DateTimeZone(self::TIMEZONES[$random->getInt(0, count(self::TIMEZONES) - 1)]);
        $dateCount = $random->getInt(1, 21);
        [$first, $changeDate] = $this->firstDate($random, $zone, $dateCount);
        $last = $first->addDays($dateCount - 1);
        $patterns = $this->patterns($random, $first, $dateCount, $changeDate !== null);
        $editedDays = $this->editedDays($random, $first, $dateCount, $changeDate);
        $blockedTimes = $this->blockedTimes($random, $first, $dateCount);
        $service = $this->serviceTimes($random);
        $settings = new BookingSettings(
            $zone,
            BookingSettings::SLOT_INTERVALS[$random->getInt(0, count(BookingSettings::SLOT_INTERVALS) - 1)],
            $random->getInt(0, 1) === 0 ? $random->getInt(0, 180) : $random->getInt(0, 3 * self::MINUTES_IN_DAY),
            $random->getInt(1, 120),
        );
        $now = $this->now($random, $first, $dateCount);

        return new Scenario(
            seed: $this->seed,
            number: $number,
            patterns: $patterns,
            editedDays: $editedDays,
            blockedTimes: $blockedTimes,
            service: $service,
            settings: $settings,
            dates: DateRange::between($first, $last),
            now: $now,
            offeredServices: $this->offeredServices($random, $service),
        );
    }

    /**
     * About a third of scenarios start within a few days of a clock change
     * in their zone, so the change falls inside the range. Gives the first
     * date, and the date of the change if there is one.
     *
     * @return array{CarbonImmutable, ?string}
     */
    private function firstDate(Randomizer $random, DateTimeZone $zone, int $dateCount): array
    {
        $changes = array_slice($zone->getTransitions(
            CarbonImmutable::parse(self::EARLIEST_DATE, 'UTC')->getTimestamp(),
            CarbonImmutable::parse(self::LATEST_DATE, 'UTC')->getTimestamp(),
        ), 1);

        if ($changes !== [] && $random->getInt(0, 2) === 0) {
            $change = $changes[$random->getInt(0, count($changes) - 1)];
            $changeDate = CarbonImmutable::createFromTimestamp($change['ts'], $zone)->format('Y-m-d');

            return [CarbonImmutable::parse($changeDate, 'UTC')->subDays($random->getInt(0, min(3, $dateCount - 1))), $changeDate];
        }

        $earliest = CarbonImmutable::parse(self::EARLIEST_DATE, 'UTC');
        $days = intdiv(CarbonImmutable::parse(self::LATEST_DATE, 'UTC')->getTimestamp() - $earliest->getTimestamp(), 86400);

        return [$earliest->addDays($random->getInt(0, $days + 1 - $dateCount)), null];
    }

    /**
     * Anywhere from 3 days before the first date to the end of the last, to
     * the minute, and sometimes with seconds. Two thirds of scenarios look
     * from before the first date, so most of their dates can offer times.
     */
    private function now(Randomizer $random, CarbonImmutable $first, int $dateCount): CarbonImmutable
    {
        $latestDay = $random->getInt(0, 2) === 0 ? $dateCount + 3 : 3;
        $now = $first->subDays(3)->addMinutes($random->getInt(0, $latestDay * self::MINUTES_IN_DAY));

        return $random->getInt(0, 3) === 0 ? $now->addSeconds($random->getInt(1, 59)) : $now;
    }

    /**
     * 0 to 3 patterns that don't overlap, starting up to 60 days before the
     * first date, some with gaps between them and some open-ended.
     *
     * @return list<RepeatingPattern>
     */
    private function patterns(Randomizer $random, CarbonImmutable $first, int $dateCount, bool $nearChange): array
    {
        $starts = [];

        for ($index = $random->getInt(0, 3); $index > 0; $index--) {
            $starts[] = $random->getInt(-60, $dateCount + 5);
        }

        $starts = array_values(array_unique($starts));
        sort($starts);
        $patterns = [];

        foreach ($starts as $index => $start) {
            $startsOn = $first->addDays($start);
            $nextStart = $starts[$index + 1] ?? null;
            $endsOn = match (true) {
                $nextStart !== null => $first->addDays(max($start, $nextStart - 1 - $random->getInt(0, 3))),
                $random->getInt(0, 1) === 0 => null,
                default => $startsOn->addDays($random->getInt(0, 30)),
            };
            $weeks = [];

            for ($week = $random->getInt(1, PatternWeeks::MAX_WEEKS); $week > 0; $week--) {
                $weeks[] = new WeekHours(...array_map(fn (): DayHours => $this->dayHours($random, $nearChange), range(1, 7)));
            }

            $patterns[] = new RepeatingPattern(
                $startsOn,
                $endsOn,
                $startsOn->startOfWeek(CarbonImmutable::MONDAY)->subWeeks($random->getInt(0, 3)),
                new PatternWeeks($weeks),
            );
        }

        return $patterns;
    }

    /**
     * 0 to 5 edited days in or near the range, some with no hours. Three
     * quarters of the scenarios near a clock change also edit the date of
     * the change, with night hours, so the change falls inside working time.
     *
     * @return array<string, DayHours>
     */
    private function editedDays(Randomizer $random, CarbonImmutable $first, int $dateCount, ?string $changeDate): array
    {
        $editedDays = [];

        for ($index = $random->getInt(0, 5); $index > 0; $index--) {
            $date = $first->addDays($random->getInt(-2, $dateCount + 1))->format('Y-m-d');
            $editedDays[$date] = $random->getInt(0, 3) === 0 ? new DayHours : $this->dayHours($random, $changeDate !== null);
        }

        if ($changeDate !== null && $random->getInt(0, 3) !== 0) {
            $editedDays[$changeDate] = $this->dayHours($random, true);
        }

        return $editedDays;
    }

    /**
     * 0 to 15 periods to the minute, in UTC. Some overlap, some cross
     * midnight or last days, and some start before the range or end after it.
     *
     * @return list<TimeRange>
     */
    private function blockedTimes(Randomizer $random, CarbonImmutable $first, int $dateCount): array
    {
        $blockedTimes = [];

        for ($index = $random->getInt(0, 15); $index > 0; $index--) {
            $start = $first->subDay()->addMinutes($random->getInt(0, ($dateCount + 2) * self::MINUTES_IN_DAY));
            $minutes = $random->getInt(0, 9) === 0
                ? $random->getInt(241, 3 * self::MINUTES_IN_DAY)
                : $random->getInt(1, 240);
            $blockedTimes[] = new TimeRange($start, $start->addMinutes($minutes));
        }

        return $blockedTimes;
    }

    /**
     * 0 to 3 ranges in 5-minute steps. Their ends lean towards 00:00, 24:00
     * and the night hours from 22:00 to 05:00, when clocks change, and more
     * so in a scenario that starts near a clock change.
     */
    private function dayHours(Randomizer $random, bool $nearChange): DayHours
    {
        $ends = [];

        for ($index = 2 * $random->getInt(0, 3); $index > 0; $index--) {
            $ends[] = match (min($random->getInt(0, 3), $nearChange ? 1 : 3)) {
                0 => $random->getInt(0, 1) * self::MINUTES_IN_DAY,
                1 => (self::MINUTES_IN_DAY + LocalTimeRange::MINUTE_STEP * $random->getInt(-24, 60)) % self::MINUTES_IN_DAY,
                default => LocalTimeRange::MINUTE_STEP * $random->getInt(0, self::MINUTES_IN_DAY / LocalTimeRange::MINUTE_STEP),
            };
        }

        $ends = array_values(array_unique($ends));
        sort($ends);
        $ranges = [];

        for ($index = 0; $index + 1 < count($ends); $index += 2) {
            $ranges[] = new LocalTimeRange($ends[$index], $ends[$index + 1]);
        }

        return new DayHours($ranges);
    }

    /**
     * 1 to 6 offered services in random order, one of them the service
     * searched for. A quarter of the room weights are 0, and the rest 1 to
     * 10. One in six of the others has the searched-for service's times,
     * so some kinds of appointment are merged. These are drawn last, so the
     * scenarios' diaries and searches are the ones 04 checked.
     *
     * @return list<OfferedService>
     */
    private function offeredServices(Randomizer $random, ServiceTimes $searched): array
    {
        $services = [new OfferedService($searched, $this->roomWeight($random))];

        for ($index = $random->getInt(0, 5); $index > 0; $index--) {
            $times = $random->getInt(0, 5) === 0 ? $searched : $this->serviceTimes($random);
            $services[] = new OfferedService($times, $this->roomWeight($random));
        }

        return $random->shuffleArray($services);
    }

    /**
     * 5 minutes to 4 hours, in 5-minute steps, with random buffers.
     */
    private function serviceTimes(Randomizer $random): ServiceTimes
    {
        return new ServiceTimes(5 * $random->getInt(1, 48), $this->buffer($random), $this->buffer($random));
    }

    private function roomWeight(Randomizer $random): int
    {
        return $random->getInt(0, 3) === 0 ? 0 : $random->getInt(1, OfferedService::MAX_ROOM_WEIGHT);
    }

    /**
     * Half of buffers are none; the rest are 5 to 60 minutes.
     */
    private function buffer(Randomizer $random): int
    {
        return $random->getInt(0, 1) === 0 ? 0 : 5 * $random->getInt(1, 12);
    }
}
