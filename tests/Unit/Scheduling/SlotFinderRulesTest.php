<?php

namespace Tests\Unit\Scheduling;

use App\Scheduling\Availability;
use App\Scheduling\DayAvailability;
use App\Scheduling\LocalClock;
use App\Scheduling\Slot;
use App\Scheduling\SlotFinder;
use App\Scheduling\TimeRange;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Tests\Support\Scheduling\Scenario;
use Tests\Support\Scheduling\ScenarioGenerator;

/**
 * Rules that hold for every diary, checked over hundreds of random ones.
 * The seed and the number of scenarios are fixed, so CI always runs the
 * same ones. SCHEDULING_SEED and SCHEDULING_SCENARIOS change them, to look
 * for new edge cases:
 *
 *     SCHEDULING_SEED=7 SCHEDULING_SCENARIOS=5000 php artisan test --filter=SlotFinderRulesTest
 *
 * A failure names the rule, the seed and the scenario, and prints the
 * scenario as JSON, so it can be turned into a worked example.
 *
 * Each rule is checked by its own method. The scenarios and the engine's
 * results are worked out once and shared by the methods, since they only
 * read them.
 */
class SlotFinderRulesTest extends TestCase
{
    private const int DEFAULT_SEED = 20261104;

    private const int DEFAULT_SCENARIOS = 300;

    private const int SECONDS_IN_MINUTE = 60;

    /**
     * @var array<string, list<array{Scenario, Availability}>>
     */
    private static array $results = [];

    public function test_rule_1_each_slots_times_agree_with_the_service(): void
    {
        foreach ($this->results() as [$scenario, $availability]) {
            foreach ($this->slotsOf($availability) as [$day, $slot]) {
                $start = $slot->startsAt->getTimestamp();
                $service = $scenario->service;

                $this->check(
                    $slot->endsAt->getTimestamp() === $start + $service->durationMinutes * self::SECONDS_IN_MINUTE
                        && $slot->blockedFrom->getTimestamp() === $start - $service->bufferBeforeMinutes * self::SECONDS_IN_MINUTE
                        && $slot->blockedUntil->getTimestamp() === $slot->endsAt->getTimestamp() + $service->bufferAfterMinutes * self::SECONDS_IN_MINUTE,
                    'each slot\'s times agree with the service',
                    $scenario,
                    "{$day->date}: {$this->describe($slot)}",
                );
            }
        }
    }

    public function test_rule_2_each_slot_lies_inside_working_time_and_overlaps_no_blocked_time(): void
    {
        foreach ($this->results() as [$scenario, $availability]) {
            foreach ($this->slotsOf($availability) as [$day, $slot]) {
                $from = $slot->blockedFrom->getTimestamp();
                $until = $slot->blockedUntil->getTimestamp();

                $this->check(
                    $this->insideOne($from, $until, $this->timestamps($day->workingTimes))
                        && ! $this->overlapsAny($from, $until, $this->timestamps($scenario->blockedTimes)),
                    'each slot, with its buffers, lies inside working time and overlaps no blocked time',
                    $scenario,
                    "{$day->date}: {$this->describe($slot)}",
                );
            }
        }
    }

    public function test_rule_3_each_slot_starts_on_the_slot_interval_on_its_days_local_date(): void
    {
        foreach ($this->results() as [$scenario, $availability]) {
            foreach ($this->slotsOf($availability) as [$day, $slot]) {
                $local = $slot->startsAt->setTimezone($scenario->settings->timezone);
                $minuteOfDay = $local->hour * 60 + $local->minute;

                $this->check(
                    $local->format('Y-m-d') === $day->date
                        && $local->second === 0
                        && $minuteOfDay % $scenario->settings->slotIntervalMinutes === 0,
                    'each slot starts on the slot interval, on its day\'s local date',
                    $scenario,
                    "{$day->date}: {$this->describe($slot)}",
                );
            }
        }
    }

    public function test_rule_4_each_slot_is_inside_the_booking_window(): void
    {
        foreach ($this->results() as [$scenario, $availability]) {
            foreach ($this->slotsOf($availability) as [$day, $slot]) {
                $this->check(
                    $this->insideWindow($scenario, $slot->startsAt->getTimestamp(), $day->date),
                    'each slot is inside the booking window',
                    $scenario,
                    "{$day->date}: {$this->describe($slot)}",
                );
            }
        }
    }

    /**
     * For each date, try every clock time on the interval, and check it
     * directly against the working times, the blocked times and the window,
     * without free gaps. This catches times that are missed as well as
     * times that shouldn't be there.
     */
    public function test_rule_5_the_slots_are_exactly_those_a_brute_force_check_finds(): void
    {
        foreach ($this->results() as [$scenario, $availability]) {
            $clock = new LocalClock($scenario->settings->timezone);
            $hours = $scenario->diary()->workingHours;
            $blocked = $this->timestamps($scenario->blockedTimes);
            $service = $scenario->service;

            foreach ($availability->days as $day) {
                $date = CarbonImmutable::parse($day->date, 'UTC');
                $workingTimes = $this->timestamps($clock->workingTimes($date, $hours->on($date)));
                $expected = [];

                for ($minute = 0; $minute < 1440; $minute += $scenario->settings->slotIntervalMinutes) {
                    $start = $clock->startTimeOn($date, $minute)?->getTimestamp();

                    if ($start === null) {
                        continue;
                    }

                    $from = $start - $service->bufferBeforeMinutes * self::SECONDS_IN_MINUTE;
                    $until = $start + ($service->durationMinutes + $service->bufferAfterMinutes) * self::SECONDS_IN_MINUTE;

                    if ($this->insideOne($from, $until, $workingTimes)
                        && ! $this->overlapsAny($from, $until, $blocked)
                        && $this->insideWindow($scenario, $start, $day->date)) {
                        $expected[] = $start;
                    }
                }

                $actual = array_map(fn (Slot $slot): int => $slot->startsAt->getTimestamp(), $day->slots);

                $this->check(
                    $actual === $expected,
                    'the slots are exactly those a brute-force check finds',
                    $scenario,
                    sprintf(
                        '%s: expected %s, got %s',
                        $day->date,
                        $this->localTimes($expected, $scenario->settings->timezone),
                        $this->localTimes($actual, $scenario->settings->timezone),
                    ),
                );
            }
        }
    }

    /**
     * Works out each day's free time a second way: it cuts the working
     * times at every blocked time's start and end, keeps the pieces no
     * blocked time touches, and joins pieces that meet.
     */
    public function test_rule_6_each_days_free_gaps_are_its_working_times_minus_blocked_time(): void
    {
        foreach ($this->results() as [$scenario, $availability]) {
            $blocked = $this->timestamps($scenario->blockedTimes);

            foreach ($availability->days as $day) {
                $expected = [];

                foreach ($this->timestamps($day->workingTimes) as [$start, $end]) {
                    $cuts = [$start, $end];

                    foreach ($blocked as [$blockedFrom, $blockedUntil]) {
                        $cuts = [...$cuts, ...array_filter([$blockedFrom, $blockedUntil], fn (int $cut): bool => $start < $cut && $cut < $end)];
                    }

                    $cuts = array_values(array_unique($cuts));
                    sort($cuts);

                    for ($index = 0; $index + 1 < count($cuts); $index++) {
                        [$pieceStart, $pieceEnd] = [$cuts[$index], $cuts[$index + 1]];

                        if ($this->overlapsAny($pieceStart, $pieceEnd, $blocked)) {
                            continue;
                        }

                        $last = count($expected) - 1;

                        if ($last >= 0 && $expected[$last][1] === $pieceStart) {
                            $expected[$last][1] = $pieceEnd;
                        } else {
                            $expected[] = [$pieceStart, $pieceEnd];
                        }
                    }
                }

                $gaps = $this->timestamps($day->freeGaps);

                $this->check(
                    $gaps === $expected,
                    'each day\'s free gaps are its working times minus blocked time',
                    $scenario,
                    "{$day->date}: expected ".json_encode($expected).', got '.json_encode($gaps),
                );
                $this->check(
                    $this->inOrderWithoutTouching($this->timestamps($day->workingTimes)) && $this->inOrderWithoutTouching($gaps),
                    'working times and free gaps are in time order, apart from each other',
                    $scenario,
                    $day->date,
                );

                $previousStart = null;

                foreach ($day->slots as $slot) {
                    $gap = [$slot->gap->start->getTimestamp(), $slot->gap->end->getTimestamp()];
                    $start = $slot->startsAt->getTimestamp();

                    $this->check(
                        in_array($gap, $gaps, true)
                            && $gap[0] <= $slot->blockedFrom->getTimestamp()
                            && $slot->blockedUntil->getTimestamp() <= $gap[1]
                            && ($previousStart === null || $previousStart < $start),
                        'each slot\'s gap is one of its day\'s free gaps and contains it, and the slots are in time order',
                        $scenario,
                        "{$day->date}: {$this->describe($slot)}",
                    );

                    $previousStart = $start;
                }
            }
        }
    }

    public function test_rule_7_there_is_one_day_for_each_date_in_order(): void
    {
        foreach ($this->results() as [$scenario, $availability]) {
            $expected = [];
            $utc = new DateTimeZone('UTC');
            $date = new DateTimeImmutable($scenario->dates->first, $utc);
            $last = new DateTimeImmutable($scenario->dates->last, $utc);

            while ($date <= $last) {
                $expected[] = $date->format('Y-m-d');
                $date = $date->modify('+1 day');
            }

            $actual = array_map(fn (DayAvailability $day): string => $day->date, $availability->days);

            $this->check($actual === $expected, 'there\'s one day for each date, in order', $scenario, implode(', ', $actual));
        }
    }

    /**
     * Each scenario with the engine's result, worked out once for each seed
     * and number of scenarios.
     *
     * @return list<array{Scenario, Availability}>
     */
    private function results(): array
    {
        $seed = $this->setting('SCHEDULING_SEED', self::DEFAULT_SEED);
        $count = $this->setting('SCHEDULING_SCENARIOS', self::DEFAULT_SCENARIOS);

        return self::$results["{$seed}:{$count}"] ??= array_map(function (int $number) use ($seed): array {
            $scenario = (new ScenarioGenerator($seed))->scenario($number);

            return [$scenario, (new SlotFinder)->find(
                $scenario->diary(),
                $scenario->service,
                $scenario->settings,
                $scenario->dates,
                $scenario->now,
            )];
        }, range(1, $count));
    }

    private function setting(string $name, int $default): int
    {
        $value = getenv($name);

        if ($value === false || $value === '') {
            return $default;
        }

        $number = filter_var($value, FILTER_VALIDATE_INT);

        if ($number === false || $number < 1) {
            $this->fail("{$name} must be a whole number of 1 or more, got \"{$value}\".");
        }

        return $number;
    }

    private function check(bool $holds, string $rule, Scenario $scenario, string $detail): void
    {
        if (! $holds) {
            $this->fail(sprintf(
                "Rule failed: %s.\nSeed %d, scenario %d. %s\n%s",
                $rule,
                $scenario->seed,
                $scenario->number,
                $detail,
                $scenario->toJson(),
            ));
        }

        $this->addToAssertionCount(1);
    }

    /**
     * @return list<array{DayAvailability, Slot}>
     */
    private function slotsOf(Availability $availability): array
    {
        $slots = [];

        foreach ($availability->days as $day) {
            foreach ($day->slots as $slot) {
                $slots[] = [$day, $slot];
            }
        }

        return $slots;
    }

    /**
     * Whether a start time is no sooner than the minimum notice and on a
     * date no later than today plus the maximum advance, both worked out
     * here rather than by BookingWindow.
     */
    private function insideWindow(Scenario $scenario, int $start, string $date): bool
    {
        $settings = $scenario->settings;
        $today = $scenario->now->setTimezone($settings->timezone)->format('Y-m-d');

        return $start >= $scenario->now->getTimestamp() + $settings->minNoticeMinutes * self::SECONDS_IN_MINUTE
            && $this->dayNumber($date) - $this->dayNumber($today) <= $settings->maxAdvanceDays;
    }

    private function dayNumber(string $date): int
    {
        return intdiv((new DateTimeImmutable($date, new DateTimeZone('UTC')))->getTimestamp(), 86400);
    }

    /**
     * @param  list<TimeRange>  $ranges
     * @return list<array{int, int}>
     */
    private function timestamps(array $ranges): array
    {
        return array_map(fn (TimeRange $range): array => [$range->start->getTimestamp(), $range->end->getTimestamp()], $ranges);
    }

    /**
     * @param  list<array{int, int}>  $ranges
     */
    private function insideOne(int $from, int $until, array $ranges): bool
    {
        foreach ($ranges as [$start, $end]) {
            if ($start <= $from && $until <= $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{int, int}>  $ranges
     */
    private function overlapsAny(int $from, int $until, array $ranges): bool
    {
        foreach ($ranges as [$start, $end]) {
            if ($start < $until && $from < $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{int, int}>  $ranges
     */
    private function inOrderWithoutTouching(array $ranges): bool
    {
        for ($index = 1; $index < count($ranges); $index++) {
            if ($ranges[$index - 1][1] >= $ranges[$index][0]) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<int>  $timestamps
     */
    private function localTimes(array $timestamps, DateTimeZone $zone): string
    {
        return '['.implode(', ', array_map(
            fn (int $timestamp): string => CarbonImmutable::createFromTimestamp($timestamp, $zone)->format('H:i T'),
            $timestamps,
        )).']';
    }

    private function describe(Slot $slot): string
    {
        return sprintf(
            'slot %s to %s, blocked %s to %s, in gap %s to %s',
            $slot->startsAt->toIso8601String(),
            $slot->endsAt->toIso8601String(),
            $slot->blockedFrom->toIso8601String(),
            $slot->blockedUntil->toIso8601String(),
            $slot->gap->start->toIso8601String(),
            $slot->gap->end->toIso8601String(),
        );
    }
}
