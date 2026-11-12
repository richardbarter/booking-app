<?php

namespace Tests\Unit\Scheduling;

use App\Scheduling\Availability;
use App\Scheduling\DayAvailability;
use App\Scheduling\LocalClock;
use App\Scheduling\OfferedService;
use App\Scheduling\ServiceFit;
use App\Scheduling\ServiceTimes;
use App\Scheduling\Slot;
use App\Scheduling\SlotFinder;
use App\Scheduling\TimeRange;
use Carbon\CarbonImmutable;
use Closure;
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
 * Each rule has its own test method. The rules are all checked in one pass
 * over the scenarios, the first time a method asks, keeping only how many
 * checks passed and each rule's first failure. Keeping every scenario's
 * result for the other methods would take over 200MB.
 */
class SlotFinderRulesTest extends TestCase
{
    private const int DEFAULT_SEED = 20261104;

    private const int DEFAULT_SCENARIOS = 300;

    private const int SECONDS_IN_MINUTE = 60;

    /**
     * Rule 12 tries every choice of start times only on pieces this long
     * or shorter, and for every third slot of each day, so it stays quick.
     * Neighbouring slots leave pieces one start time apart, so every third
     * still covers pieces of every length.
     */
    private const int LONGEST_PIECE_TO_COUNT_SECONDS = 4 * 3600;

    private const int SLOTS_PER_COUNT_CHECK = 3;

    /**
     * For each seed and number of scenarios, and each rule: how many checks
     * passed, and the first failure.
     *
     * @var array<string, array<int, array{checks: int<0, max>, failure: ?string}>>
     */
    private static array $outcomes = [];

    /**
     * The current scenario's clock times on the interval for each date, as
     * timestamps, worked out once for the rules that need them.
     *
     * @var array{scenario: ?string, dates: array<string, list<int>>}
     */
    private array $clockTimes = ['scenario' => null, 'dates' => []];

    public function test_rule_1_each_slots_times_agree_with_the_service(): void
    {
        $this->assertRuleHolds(1);
    }

    public function test_rule_2_each_slot_lies_inside_working_time_and_overlaps_no_blocked_time(): void
    {
        $this->assertRuleHolds(2);
    }

    public function test_rule_3_each_slot_starts_on_the_slot_interval_on_its_days_local_date(): void
    {
        $this->assertRuleHolds(3);
    }

    public function test_rule_4_each_slot_is_inside_the_booking_window(): void
    {
        $this->assertRuleHolds(4);
    }

    public function test_rule_5_the_slots_are_exactly_those_a_brute_force_check_finds(): void
    {
        $this->assertRuleHolds(5);
    }

    public function test_rule_6_each_days_free_gaps_are_its_working_times_minus_blocked_time(): void
    {
        $this->assertRuleHolds(6);
    }

    public function test_rule_7_there_is_one_day_for_each_date_in_order(): void
    {
        $this->assertRuleHolds(7);
    }

    public function test_rule_8_each_slots_waste_parts_are_0_or_more_and_add_up(): void
    {
        $this->assertRuleHolds(8);
    }

    public function test_rule_9_each_gaps_best_slot_has_extra_waste_0_and_the_others_are_measured_from_it(): void
    {
        $this->assertRuleHolds(9);
    }

    public function test_rule_10_a_slots_waste_does_not_depend_on_now(): void
    {
        $this->assertRuleHolds(10);
    }

    public function test_rule_11_stranded_minutes_match_a_brute_force_check(): void
    {
        $this->assertRuleHolds(11);
    }

    public function test_rule_12_counts_are_the_most_that_fit(): void
    {
        $this->assertRuleHolds(12);
    }

    public function test_rule_13_each_days_stranded_and_fragmented_gaps_match_their_definitions(): void
    {
        $this->assertRuleHolds(13);
    }

    private function assertRuleHolds(int $rule): void
    {
        $outcome = $this->outcomes()[$rule];

        if ($outcome['failure'] !== null) {
            $this->fail($outcome['failure']);
        }

        $this->addToAssertionCount($outcome['checks']);
    }

    /**
     * @return array<int, array{checks: int<0, max>, failure: ?string}>
     */
    private function outcomes(): array
    {
        $seed = $this->setting('SCHEDULING_SEED', self::DEFAULT_SEED);
        $count = $this->setting('SCHEDULING_SCENARIOS', self::DEFAULT_SCENARIOS);

        return self::$outcomes["{$seed}:{$count}"] ??= $this->checkEveryRule($seed, $count);
    }

    /**
     * @return array<int, array{checks: int<0, max>, failure: ?string}>
     */
    private function checkEveryRule(int $seed, int $count): array
    {
        $rules = $this->rules();
        $outcomes = array_map(fn (): array => ['checks' => 0, 'failure' => null], $rules);
        $generator = new ScenarioGenerator($seed);

        for ($number = 1; $number <= $count; $number++) {
            $scenario = $generator->scenario($number);
            $availability = (new SlotFinder)->find(
                $scenario->diary(),
                $scenario->service,
                $scenario->settings,
                $scenario->dates,
                $scenario->now,
            );

            foreach ($rules as $rule => [$description, $checkRule]) {
                if ($outcomes[$rule]['failure'] !== null) {
                    continue;
                }

                $checkRule($scenario, $availability, function (bool $holds, string $detail) use (&$outcomes, $rule, $description, $scenario): void {
                    if ($outcomes[$rule]['failure'] !== null) {
                        return;
                    }

                    if ($holds) {
                        $outcomes[$rule]['checks']++;

                        return;
                    }

                    $outcomes[$rule]['failure'] = sprintf(
                        "Rule %d failed: %s.\nSeed %d, scenario %d. %s\n%s",
                        $rule,
                        $description,
                        $scenario->seed,
                        $scenario->number,
                        $detail,
                        $scenario->toJson(),
                    );
                });
            }
        }

        return $outcomes;
    }

    /**
     * Each rule's description and its check. A check is given a scenario,
     * the engine's result, and a function to call with whether each part of
     * the rule holds.
     *
     * @return array<int, array{string, Closure(Scenario, Availability, Closure(bool, string): void): void}>
     */
    private function rules(): array
    {
        return [
            1 => ['each slot\'s times agree with the service', $this->slotTimesAgree(...)],
            2 => ['each slot, with its buffers, lies inside working time and overlaps no blocked time', $this->slotsFit(...)],
            3 => ['each slot starts on the slot interval, on its day\'s local date', $this->slotsOnTheInterval(...)],
            4 => ['each slot is inside the booking window', $this->slotsInsideTheWindow(...)],
            5 => ['the slots are exactly those a brute-force check finds', $this->slotsMatchBruteForce(...)],
            6 => ['each day\'s free gaps are its working times minus blocked time, and each slot sits in one', $this->freeGapsMatch(...)],
            7 => ['there\'s one day for each date, in order', $this->oneDayForEachDate(...)],
            8 => ['each slot\'s waste parts are 0 or more, and its waste is their sum', $this->wastePartsAddUp(...)],
            9 => ['each free gap with slots has one with extra waste 0, and each slot\'s extra waste is how much more it is than that one', $this->bestInEachGap(...)],
            10 => ['a slot\'s waste doesn\'t depend on now', $this->wasteIgnoresNow(...)],
            11 => ['each slot\'s stranded minutes are those a brute-force check finds', $this->strandedMatchesBruteForce(...)],
            12 => ['counts are the most that fit, found by trying every choice of start times', $this->countsMatchBruteForce(...)],
            13 => ['each day\'s stranded and fragmented gaps match their definitions', $this->gapSummaryMatches(...)],
        ];
    }

    /**
     * @param  Closure(bool, string): void  $check
     */
    private function slotTimesAgree(Scenario $scenario, Availability $availability, Closure $check): void
    {
        $service = $scenario->service;

        foreach ($this->slotsOf($availability) as [$day, $slot]) {
            $start = $slot->startsAt->getTimestamp();
            $end = $slot->endsAt->getTimestamp();

            $check(
                $end === $start + $service->durationMinutes * self::SECONDS_IN_MINUTE
                    && $slot->blockedFrom->getTimestamp() === $start - $service->bufferBeforeMinutes * self::SECONDS_IN_MINUTE
                    && $slot->blockedUntil->getTimestamp() === $end + $service->bufferAfterMinutes * self::SECONDS_IN_MINUTE,
                "{$day->date}: {$this->describe($slot)}",
            );
        }
    }

    /**
     * @param  Closure(bool, string): void  $check
     */
    private function slotsFit(Scenario $scenario, Availability $availability, Closure $check): void
    {
        $blocked = $this->timestamps($scenario->blockedTimes);

        foreach ($this->slotsOf($availability) as [$day, $slot]) {
            $from = $slot->blockedFrom->getTimestamp();
            $until = $slot->blockedUntil->getTimestamp();

            $check(
                $this->insideOne($from, $until, $this->timestamps($day->workingTimes)) && ! $this->overlapsAny($from, $until, $blocked),
                "{$day->date}: {$this->describe($slot)}",
            );
        }
    }

    /**
     * @param  Closure(bool, string): void  $check
     */
    private function slotsOnTheInterval(Scenario $scenario, Availability $availability, Closure $check): void
    {
        foreach ($this->slotsOf($availability) as [$day, $slot]) {
            $local = $slot->startsAt->setTimezone($scenario->settings->timezone);

            $check(
                $local->format('Y-m-d') === $day->date
                    && $local->second === 0
                    && ($local->hour * 60 + $local->minute) % $scenario->settings->slotIntervalMinutes === 0,
                "{$day->date}: {$this->describe($slot)}",
            );
        }
    }

    /**
     * @param  Closure(bool, string): void  $check
     */
    private function slotsInsideTheWindow(Scenario $scenario, Availability $availability, Closure $check): void
    {
        foreach ($this->slotsOf($availability) as [$day, $slot]) {
            $check(
                $this->insideWindow($scenario, $slot->startsAt->getTimestamp(), $day->date),
                "{$day->date}: {$this->describe($slot)}",
            );
        }
    }

    /**
     * For each date, try every clock time on the interval, and check it
     * directly against the working times, the blocked times and the window,
     * without free gaps. This catches times that are missed as well as
     * times that shouldn't be there.
     *
     * @param  Closure(bool, string): void  $check
     */
    private function slotsMatchBruteForce(Scenario $scenario, Availability $availability, Closure $check): void
    {
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

            $check($actual === $expected, sprintf(
                '%s: expected %s, got %s',
                $day->date,
                $this->localTimes($expected, $scenario->settings->timezone),
                $this->localTimes($actual, $scenario->settings->timezone),
            ));
        }
    }

    /**
     * Works out each day's free time a second way: it cuts the working
     * times at every blocked time's start and end, keeps the pieces no
     * blocked time touches, and joins pieces that meet. Then checks the
     * lists are in time order, and each slot's gap is one of the day's
     * gaps and contains it.
     *
     * @param  Closure(bool, string): void  $check
     */
    private function freeGapsMatch(Scenario $scenario, Availability $availability, Closure $check): void
    {
        $blocked = $this->timestamps($scenario->blockedTimes);

        foreach ($availability->days as $day) {
            $workingTimes = $this->timestamps($day->workingTimes);
            $gaps = $this->timestamps($day->freeGaps);
            $expected = [];

            foreach ($workingTimes as [$start, $end]) {
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

            $check($gaps === $expected, "{$day->date}: free gaps expected ".json_encode($expected).', got '.json_encode($gaps));
            $check(
                $this->inOrderWithoutTouching($workingTimes) && $this->inOrderWithoutTouching($gaps),
                "{$day->date}: working times and free gaps out of order, or touching",
            );

            $previousStart = null;

            foreach ($day->slots as $slot) {
                $gap = [$slot->gap->start->getTimestamp(), $slot->gap->end->getTimestamp()];
                $start = $slot->startsAt->getTimestamp();

                $check(
                    in_array($gap, $gaps, true)
                        && $gap[0] <= $slot->blockedFrom->getTimestamp()
                        && $slot->blockedUntil->getTimestamp() <= $gap[1]
                        && ($previousStart === null || $previousStart < $start),
                    "{$day->date}: {$this->describe($slot)}",
                );

                $previousStart = $start;
            }
        }
    }

    /**
     * @param  Closure(bool, string): void  $check
     */
    private function oneDayForEachDate(Scenario $scenario, Availability $availability, Closure $check): void
    {
        $utc = new DateTimeZone('UTC');
        $date = new DateTimeImmutable($scenario->dates->first, $utc);
        $last = new DateTimeImmutable($scenario->dates->last, $utc);
        $expected = [];

        while ($date <= $last) {
            $expected[] = $date->format('Y-m-d');
            $date = $date->modify('+1 day');
        }

        $actual = array_map(fn (DayAvailability $day): string => $day->date, $availability->days);

        $check($actual === $expected, 'days: '.implode(', ', $actual));
    }

    /**
     * @param  Closure(bool, string): void  $check
     */
    private function wastePartsAddUp(Scenario $scenario, Availability $availability, Closure $check): void
    {
        foreach ($this->slotsOf($availability) as [$day, $slot]) {
            $waste = $slot->waste;

            $check(
                $waste->strandedMinutes >= 0
                    && $waste->lostRoomMinutes >= 0
                    && $waste->splitMinutes >= 0
                    && $waste->minutes === $waste->strandedMinutes + $waste->lostRoomMinutes + $waste->splitMinutes,
                "{$day->date}: {$this->describe($slot)}",
            );
        }
    }

    /**
     * @param  Closure(bool, string): void  $check
     */
    private function bestInEachGap(Scenario $scenario, Availability $availability, Closure $check): void
    {
        foreach ($availability->days as $day) {
            $byGap = [];

            foreach ($day->slots as $slot) {
                $byGap[$slot->gap->start->getTimestamp()][] = $slot;
            }

            foreach ($byGap as $slots) {
                $lowest = min(array_map(fn (Slot $slot): int => $slot->waste->minutes, $slots));

                foreach ($slots as $slot) {
                    $check(
                        $slot->waste->extraMinutes === $slot->waste->minutes - $lowest,
                        "{$day->date}: lowest waste in the gap {$lowest}, {$this->describe($slot)}",
                    );
                }
            }
        }
    }

    /**
     * Runs the same search with now three days earlier, which moves the
     * booking window, and compares the slots offered both times.
     *
     * @param  Closure(bool, string): void  $check
     */
    private function wasteIgnoresNow(Scenario $scenario, Availability $availability, Closure $check): void
    {
        $earlier = (new SlotFinder)->find(
            $scenario->diary(),
            $scenario->service,
            $scenario->settings,
            $scenario->dates,
            $scenario->now->subDays(3),
        );
        $earlierWaste = [];

        foreach ($this->slotsOf($earlier) as [, $slot]) {
            $earlierWaste[$slot->startsAt->getTimestamp()] = $slot->waste->minutes;
        }

        foreach ($this->slotsOf($availability) as [$day, $slot]) {
            $start = $slot->startsAt->getTimestamp();

            if (isset($earlierWaste[$start])) {
                $check(
                    $earlierWaste[$start] === $slot->waste->minutes,
                    "{$day->date}: waste {$slot->waste->minutes}, but {$earlierWaste[$start]} three days earlier, {$this->describe($slot)}",
                );
            }
        }
    }

    /**
     * For each piece a slot leaves, tries every clock time on the interval
     * and every offered service, whatever its weight. The piece is stranded
     * exactly when none fits.
     *
     * @param  Closure(bool, string): void  $check
     */
    private function strandedMatchesBruteForce(Scenario $scenario, Availability $availability, Closure $check): void
    {
        foreach ($this->slotsOf($availability) as [$day, $slot]) {
            $times = $this->clockTimesOn($scenario, $day->date);
            $expected = 0;

            foreach ($this->piecesLeftBy($slot) as [$from, $until]) {
                if (! $this->anyServiceFits($scenario->offeredServices, $times, $from, $until)) {
                    $expected += intdiv($until - $from, self::SECONDS_IN_MINUTE);
                }
            }

            $check(
                $slot->waste->strandedMinutes === $expected,
                "{$day->date}: expected {$expected} stranded minutes, got {$slot->waste->strandedMinutes}, {$this->describe($slot)}",
            );
        }
    }

    /**
     * On each piece of up to four hours that every third slot leaves,
     * ServiceFit's count of each kind is the most found by trying every
     * choice of start times. Its counts in the two pieces never add up to
     * more than in the gap.
     *
     * @param  Closure(bool, string): void  $check
     */
    private function countsMatchBruteForce(Scenario $scenario, Availability $availability, Closure $check): void
    {
        $clock = new LocalClock($scenario->settings->timezone);

        foreach ($availability->days as $day) {
            if ($day->slots === []) {
                continue;
            }

            $times = $this->clockTimesOn($scenario, $day->date);
            $fit = new ServiceFit($clock->startTimesOn(CarbonImmutable::parse($day->date, 'UTC'), $scenario->settings->slotIntervalMinutes));

            foreach ($day->slots as $index => $slot) {
                if ($index % self::SLOTS_PER_COUNT_CHECK !== 0) {
                    continue;
                }

                $pieces = $this->piecesLeftBy($slot);
                [$gapStart, $gapEnd] = $this->wholeSecondsOf($slot->gap);

                foreach ($scenario->offeredServices as $offered) {
                    $kind = $offered->times;
                    $inPieces = 0;

                    foreach ($pieces as [$from, $until]) {
                        $count = $fit->count($kind, $from, $until);
                        $inPieces += $count;

                        if ($until - $from <= self::LONGEST_PIECE_TO_COUNT_SECONDS) {
                            $most = $this->mostThatFit($kind, $times, $from, $until);
                            $check($count === $most, "{$day->date}: counted {$count} of {$this->describeKind($kind)}, but {$most} fit in {$this->describeRange($from, $until)}");
                        }
                    }

                    $inGap = $fit->count($kind, $gapStart, $gapEnd);
                    $check($inPieces <= $inGap, "{$day->date}: {$inPieces} of {$this->describeKind($kind)} in the pieces, but {$inGap} in the gap, {$this->describe($slot)}");
                }
            }
        }
    }

    /**
     * Works out which free gaps are stranded and fragmented by trying every
     * clock time on the interval, with the kinds' weights added up here.
     *
     * @param  Closure(bool, string): void  $check
     */
    private function gapSummaryMatches(Scenario $scenario, Availability $availability, Closure $check): void
    {
        $weights = [];

        foreach ($scenario->offeredServices as $offered) {
            $key = $this->describeKind($offered->times);
            $weights[$key] = [$offered->times, ($weights[$key][1] ?? 0) + $offered->roomWeight];
        }

        $weighted = array_values(array_filter($weights, fn (array $kind): bool => $kind[1] > 0));
        $longest = $weighted === [] ? null : max(array_map(fn (array $kind): int => $kind[0]->diaryMinutes(), $weighted));
        $longestKinds = array_values(array_map(
            fn (array $kind): OfferedService => new OfferedService($kind[0]),
            array_filter($weighted, fn (array $kind): bool => $kind[0]->diaryMinutes() === $longest),
        ));

        foreach ($availability->days as $day) {
            $times = $this->clockTimesOn($scenario, $day->date);
            $summary = $day->gapSummary;
            $expectedStranded = [];
            $expectedFragmented = [];

            foreach ($day->freeGaps as $gap) {
                [$from, $until] = $this->wholeSecondsOf($gap);

                if (! $this->anyServiceFits($scenario->offeredServices, $times, $from, $until)) {
                    $expectedStranded[] = $gap;
                } elseif ($longestKinds !== [] && ! $this->anyServiceFits($longestKinds, $times, $from, $until)) {
                    $expectedFragmented[] = $gap;
                }
            }

            $check(
                $this->timestamps($summary->stranded) === $this->timestamps($expectedStranded)
                    && $this->timestamps($summary->fragmented) === $this->timestamps($expectedFragmented),
                "{$day->date}: stranded ".json_encode($this->timestamps($summary->stranded)).', fragmented '.json_encode($this->timestamps($summary->fragmented))
                    .', expected '.json_encode($this->timestamps($expectedStranded)).' and '.json_encode($this->timestamps($expectedFragmented)),
            );
            $check(
                $summary->strandedMinutes() === $this->wholeMinutes($expectedStranded)
                    && $summary->fragmentedMinutes() === $this->wholeMinutes($expectedFragmented),
                "{$day->date}: {$summary->strandedMinutes()} stranded and {$summary->fragmentedMinutes()} fragmented minutes",
            );
        }
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

    /**
     * The date's clock times on the interval, as timestamps, worked out once
     * for the current scenario. They come from LocalClock::startTimesOn(),
     * which rule 5 checks against startTimeOn() for every date.
     *
     * @return list<int>
     */
    private function clockTimesOn(Scenario $scenario, string $date): array
    {
        $key = "{$scenario->seed}:{$scenario->number}";

        if ($this->clockTimes['scenario'] !== $key) {
            $this->clockTimes = ['scenario' => $key, 'dates' => []];
        }

        return $this->clockTimes['dates'][$date] ??= array_map(
            fn (CarbonImmutable $time): int => $time->getTimestamp(),
            (new LocalClock($scenario->settings->timezone))->startTimesOn(CarbonImmutable::parse($date, 'UTC'), $scenario->settings->slotIntervalMinutes),
        );
    }

    /**
     * The pieces of its gap a slot leaves before and after it, in whole
     * seconds, with the gap's start rounded up and its end down.
     *
     * @return list<array{int, int}>
     */
    private function piecesLeftBy(Slot $slot): array
    {
        [$gapStart, $gapEnd] = $this->wholeSecondsOf($slot->gap);

        return [[$gapStart, $slot->blockedFrom->getTimestamp()], [$slot->blockedUntil->getTimestamp(), $gapEnd]];
    }

    /**
     * @return array{int, int}
     */
    private function wholeSecondsOf(TimeRange $range): array
    {
        $start = $range->start->getTimestamp();
        $end = $range->end->getTimestamp();

        return [$range->start->microsecond > 0 ? $start + 1 : $start, $end];
    }

    /**
     * @param  list<TimeRange>  $gaps
     */
    private function wholeMinutes(array $gaps): int
    {
        $minutes = 0;

        foreach ($gaps as $gap) {
            [$from, $until] = $this->wholeSecondsOf($gap);
            $minutes += intdiv($until - $from, self::SECONDS_IN_MINUTE);
        }

        return $minutes;
    }

    /**
     * Whether any of the services, at any of the clock times, fits with its
     * buffers inside the piece. The times are in order, so none from the
     * end of the piece on can fit.
     *
     * @param  list<OfferedService>  $services
     * @param  list<int>  $times
     */
    private function anyServiceFits(array $services, array $times, int $from, int $until): bool
    {
        foreach ($services as $offered) {
            foreach ($times as $time) {
                if ($time >= $until) {
                    break;
                }

                [$blockedFrom, $blockedUntil] = $this->blockedAt($offered->times, $time);

                if ($from <= $blockedFrom && $blockedUntil <= $until) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The most appointments of the kind that fit in the piece without
     * overlapping, by trying every choice: each time that fits is either
     * taken, and the next one tried is the first after it ends, or skipped.
     * Working back from the last time, each answer is remembered, so it
     * stays quick.
     *
     * @param  list<int>  $times
     */
    private function mostThatFit(ServiceTimes $kind, array $times, int $from, int $until): int
    {
        $candidates = [];

        foreach ($times as $time) {
            if ($time >= $until) {
                break;
            }

            $blocked = $this->blockedAt($kind, $time);

            if ($from <= $blocked[0] && $blocked[1] <= $until) {
                $candidates[] = $blocked;
            }
        }

        $count = count($candidates);
        $most = array_fill(0, $count + 1, 0);

        for ($index = $count - 1; $index >= 0; $index--) {
            $next = $index + 1;

            while ($next < $count && $candidates[$next][0] < $candidates[$index][1]) {
                $next++;
            }

            $most[$index] = max($most[$index + 1], 1 + $most[$next]);
        }

        return $most[0];
    }

    /**
     * @return array{int, int}
     */
    private function blockedAt(ServiceTimes $kind, int $time): array
    {
        return [
            $time - $kind->bufferBeforeMinutes * self::SECONDS_IN_MINUTE,
            $time + ($kind->durationMinutes + $kind->bufferAfterMinutes) * self::SECONDS_IN_MINUTE,
        ];
    }

    private function describeKind(ServiceTimes $kind): string
    {
        return "{$kind->bufferBeforeMinutes}+{$kind->durationMinutes}+{$kind->bufferAfterMinutes} minutes";
    }

    private function describeRange(int $from, int $until): string
    {
        return CarbonImmutable::createFromTimestamp($from, 'UTC')->toIso8601String().' to '.CarbonImmutable::createFromTimestamp($until, 'UTC')->toIso8601String();
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
