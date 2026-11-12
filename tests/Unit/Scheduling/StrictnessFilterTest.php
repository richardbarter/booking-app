<?php

namespace Tests\Unit\Scheduling;

use App\Scheduling\BookingSettings;
use App\Scheduling\DateRange;
use App\Scheduling\DayAvailability;
use App\Scheduling\Diary;
use App\Scheduling\Hours\DayHours;
use App\Scheduling\Hours\WorkingHours;
use App\Scheduling\OfferedService;
use App\Scheduling\ServiceTimes;
use App\Scheduling\Slot;
use App\Scheduling\SlotFinder;
use App\Scheduling\StrictnessFilter;
use App\Scheduling\TimeRange;
use App\Scheduling\WasteScore;
use Carbon\CarbonImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Strictness, through SlotFinder for the sample business's morning, and on
 * slots given their extra waste directly for the threshold and the
 * relaxation window. Times are in London. The sample morning is 06's:
 * free from 09:00 to 13:00 on Monday 9 November 2026, booking a 30-minute
 * follow-up, looking from a week before.
 */
class StrictnessFilterTest extends TestCase
{
    private const string MONDAY = '2026-11-09';

    /**
     * 07's table, for the follow-up in the morning. The extra waste of
     * each time is 0 at 09:00 and 12:30; 5 at 09:30, 10:30, 11:00 and
     * 12:00; 23 at 10:00 and 11:30; 32 at 09:15 and 12:15; 35 at 10:15 and
     * 11:15; 41 at 10:45; and 59 at 09:45 and 11:45.
     *
     * @return array<string, array{int, list<string>}>
     */
    public static function sampleMorning(): array
    {
        $every = ['09:00', '09:15', '09:30', '09:45', '10:00', '10:15', '10:30', '10:45', '11:00', '11:15', '11:30', '11:45', '12:00', '12:15', '12:30'];
        $upTo35 = ['09:00', '09:15', '09:30', '10:00', '10:15', '10:30', '11:00', '11:15', '11:30', '12:00', '12:15', '12:30'];
        $upTo23 = ['09:00', '09:30', '10:00', '10:30', '11:00', '11:30', '12:00', '12:30'];
        $upTo5 = ['09:00', '09:30', '10:30', '11:00', '12:00', '12:30'];

        return [
            '0' => [0, $every],
            '25, a threshold of 90 minutes' => [25, $every],
            '50, a threshold of 60 minutes' => [50, $every],
            '60, a threshold of 48 minutes' => [60, array_values(array_diff($every, ['09:45', '11:45']))],
            '70, a threshold of 36 minutes' => [70, $upTo35],
            '75, a threshold of 30 minutes' => [75, $upTo23],
            '80, a threshold of 24 minutes' => [80, $upTo23],
            '90, a threshold of 12 minutes' => [90, $upTo5],
            '95, a threshold of 6 minutes' => [95, $upTo5],
            '100, a threshold of 0' => [100, ['09:00', '12:30']],
        ];
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('sampleMorning')]
    public function test_offers_the_sample_mornings_follow_up_times_at_each_strictness(int $strictness, array $expected): void
    {
        $day = $this->sampleMorningAt($strictness);

        $this->assertSame($expected, $this->localTimes($day->slots));
    }

    public function test_keeps_each_hidden_slot_in_time_order_with_its_waste_score(): void
    {
        $day = $this->sampleMorningAt(75);

        $this->assertSame(
            ['09:15' => 32, '09:45' => 59, '10:15' => 35, '10:45' => 41, '11:15' => 35, '11:45' => 59, '12:15' => 32],
            array_combine($this->localTimes($day->hiddenSlots), array_map(fn (Slot $slot): int => $slot->waste->extraMinutes, $day->hiddenSlots)),
        );
        $this->assertSame([68, 95, 71, 77, 71, 95, 68], array_map(fn (Slot $slot): int => $slot->waste->minutes, $day->hiddenSlots));
    }

    /**
     * The threshold is 120 × (100 − strictness) ÷ 100 minutes, and a slot
     * exactly on it is offered.
     *
     * @return array<string, array{int, int, int}>
     */
    public static function thresholdEdges(): array
    {
        return [
            '1, a threshold of 118.8 minutes' => [1, 118, 119],
            '50, a threshold of 60 minutes' => [50, 60, 61],
            '99, a threshold of 1.2 minutes' => [99, 1, 2],
            '100, a threshold of 0' => [100, 0, 1],
        ];
    }

    #[DataProvider('thresholdEdges')]
    public function test_hides_a_slot_whose_extra_waste_is_above_the_threshold(int $strictness, int $offeredExtra, int $hiddenExtra): void
    {
        $offered = $this->slot('2026-11-09 09:00', $offeredExtra);
        $hidden = $this->slot('2026-11-09 09:15', $hiddenExtra);

        $split = $this->filter($strictness)->split([$offered, $hidden]);

        $this->assertSame([[$offered], [$hidden]], $split);
    }

    public function test_strictness_0_hides_nothing_however_wasteful(): void
    {
        $slots = [$this->slot('2026-11-09 09:00', 0), $this->slot('2026-11-09 09:15', 500)];

        $split = $this->filter(0)->split($slots);

        $this->assertSame([$slots, []], $split);
    }

    public function test_offers_a_slot_starting_exactly_the_relaxation_window_from_now_and_hides_one_a_minute_later(): void
    {
        $atTheEdge = $this->slot('2026-11-09 08:00', 30);
        $justAfter = $this->slot('2026-11-09 08:01', 30);

        $split = $this->filter(100, relaxationHours: 48, now: '2026-11-07 08:00')->split([$atTheEdge, $justAfter]);

        $this->assertSame([[$atTheEdge], [$justAfter]], $split);
    }

    public function test_offers_every_slot_inside_the_relaxation_window_whatever_its_waste(): void
    {
        $slots = [$this->slot('2026-11-07 10:00', 500), $this->slot('2026-11-08 17:45', 120)];

        $split = $this->filter(100, relaxationHours: 48, now: '2026-11-07 08:00')->split($slots);

        $this->assertSame([$slots, []], $split);
    }

    public function test_with_no_relaxation_window_strictness_applies_to_every_slot_after_now(): void
    {
        $soon = $this->slot('2026-11-07 08:15', 30);
        $later = $this->slot('2026-11-20 09:00', 30);

        $split = $this->filter(100, relaxationHours: 0, now: '2026-11-07 08:00')->split([$soon, $later]);

        $this->assertSame([[], [$soon, $later]], $split);
    }

    /**
     * London's clocks go forward at 01:00 on Sunday 29 March 2026. 48 real
     * hours after 10:00 on the Saturday is 11:00 on the Monday, not 10:00.
     */
    public function test_counts_the_relaxation_window_in_real_hours_across_a_clock_change(): void
    {
        $atTheEdge = $this->slot('2026-03-30 11:00', 30);
        $justAfter = $this->slot('2026-03-30 11:15', 30);

        $split = $this->filter(100, relaxationHours: 48, now: '2026-03-28 10:00')->split([$atTheEdge, $justAfter]);

        $this->assertSame([[$atTheEdge], [$justAfter]], $split);
    }

    private function sampleMorningAt(int $strictness): DayAvailability
    {
        $availability = (new SlotFinder)->find(
            new Diary(
                new WorkingHours([], [self::MONDAY => DayHours::fromArray([['09:00', '13:00']])]),
                [new OfferedService(new ServiceTimes(30)), new OfferedService(new ServiceTimes(60)), new OfferedService(new ServiceTimes(90), 5)],
            ),
            new ServiceTimes(30),
            new BookingSettings(new DateTimeZone('Europe/London'), 15, 120, 90, $strictness, 48),
            DateRange::between(CarbonImmutable::parse(self::MONDAY), CarbonImmutable::parse(self::MONDAY)),
            CarbonImmutable::parse('2026-11-02 08:00', 'Europe/London'),
        );

        $day = $availability->day(self::MONDAY);
        $this->assertNotNull($day);

        return $day;
    }

    private function filter(int $strictness, int $relaxationHours = 48, string $now = '2026-11-02 08:00'): StrictnessFilter
    {
        return new StrictnessFilter(
            new BookingSettings(new DateTimeZone('Europe/London'), 15, 0, 90, $strictness, $relaxationHours),
            CarbonImmutable::parse($now, 'Europe/London'),
        );
    }

    /**
     * A 15-minute slot at a London time, with the extra waste given. Its
     * other scores don't matter to strictness.
     */
    private function slot(string $startsAt, int $extraMinutes): Slot
    {
        $start = CarbonImmutable::parse($startsAt, 'Europe/London');
        $end = $start->addMinutes(15);

        return new Slot($start, $end, $start, $end, new TimeRange($start, $end), new WasteScore(0, 0, 0, $extraMinutes, []));
    }

    /**
     * @param  list<Slot>  $slots
     * @return list<string>
     */
    private function localTimes(array $slots): array
    {
        return array_map(fn (Slot $slot): string => $slot->startsAt->setTimezone('Europe/London')->format('H:i'), $slots);
    }
}
