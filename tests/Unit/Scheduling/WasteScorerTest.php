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
use App\Scheduling\TimeRange;
use Carbon\CarbonImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * The waste score, as SlotFinder gives it on each slot, and the day's gap
 * summary. Worked examples are in London on Monday 9 November 2026 (when
 * London is on UTC), with start times every 15 minutes, looking from a
 * week before unless a test says otherwise. Times are local. The worked
 * examples are 06's, checked there with a prototype of the formula.
 */
class WasteScorerTest extends TestCase
{
    private const string MONDAY = '2026-11-09';

    public function test_scores_the_overviews_example(): void
    {
        $day = $this->dayOf([['09:00', '10:30']], $this->thirtySixtyNinety(), new ServiceTimes(30));

        $this->assertSame([
            '09:00' => [0, 40, 0, 40, 0],
            '09:15' => [15, 70, 5, 90, 50],
            '09:30' => [0, 60, 5, 65, 25],
            '09:45' => [15, 70, 5, 90, 50],
            '10:00' => [0, 40, 0, 40, 0],
        ], $this->scores($day));
    }

    public function test_scores_the_sample_businesss_morning_for_a_follow_up(): void
    {
        $day = $this->dayOf([['09:00', '13:00']], $this->sampleServices(), new ServiceTimes(30));

        $this->assertSame([
            '09:00' => [36, 0],
            '09:15' => [68, 32],
            '09:30' => [41, 5],
            '09:45' => [95, 59],
            '10:00' => [59, 23],
            '10:15' => [71, 35],
            '10:30' => [41, 5],
            '10:45' => [77, 41],
            '11:00' => [41, 5],
            '11:15' => [71, 35],
            '11:30' => [59, 23],
            '11:45' => [95, 59],
            '12:00' => [41, 5],
            '12:15' => [68, 32],
            '12:30' => [36, 0],
        ], $this->wasteAndExtra($day));
    }

    public function test_a_full_weight_massage_raises_the_extra_waste_of_times_that_cost_room_for_one(): void
    {
        $day = $this->dayOf([['09:00', '13:00']], $this->sampleServices(massageWeight: 10), new ServiceTimes(30));

        $extra = $this->wasteAndExtra($day);
        $this->assertSame([65, 35], $extra['10:00']);
        $this->assertSame([65, 35], $extra['11:30']);
    }

    public function test_with_every_weight_0_lost_room_is_0_and_no_gap_is_fragmented(): void
    {
        $day = $this->dayOf(
            [['09:00', '13:00']],
            [new OfferedService(new ServiceTimes(30), 0), new OfferedService(new ServiceTimes(60), 0), new OfferedService(new ServiceTimes(90), 0)],
            new ServiceTimes(30),
            blocked: [['10:00', '12:00']],
        );

        $this->assertNotSame([], $day->slots);
        foreach ($day->slots as $slot) {
            $this->assertSame(0, $slot->waste->lostRoomMinutes);
            $this->assertSame([], $slot->waste->lostRoom);
        }
        $this->assertSame([], $day->gapSummary->fragmented);
    }

    public function test_lists_only_the_kinds_of_appointment_a_slot_takes_room_from(): void
    {
        $day = $this->dayOf([['09:00', '10:30']], $this->thirtySixtyNinety(), new ServiceTimes(30));

        $this->assertSame([[30, 1], [90, 1]], $this->lostRoomOf($day, '09:00'));
    }

    public function test_services_with_the_same_times_count_as_one_kind_with_their_weights_added_together(): void
    {
        $offered = [...$this->thirtySixtyNinety(), new OfferedService(new ServiceTimes(30))];

        $day = $this->dayOf([['09:00', '10:30']], $offered, new ServiceTimes(30));

        $waste = $this->slotAt($day, '09:30')->waste;
        $this->assertSame([[30, 1], [60, 1], [90, 1]], $this->lostRoomOf($day, '09:30'));
        $this->assertSame(53, $waste->lostRoomMinutes);
    }

    public function test_lost_room_counts_each_appointments_buffers_as_diary_time(): void
    {
        $withoutBuffers = $this->dayOf([['09:00', '13:00']], $this->sampleServices(), new ServiceTimes(30));
        $withABufferAfter = $this->dayOf(
            [['09:00', '13:00']],
            [new OfferedService(new ServiceTimes(30)), new OfferedService(new ServiceTimes(50, 0, 10)), new OfferedService(new ServiceTimes(90), 5)],
            new ServiceTimes(30),
        );

        $this->assertSame($this->wasteAndExtra($withoutBuffers), $this->wasteAndExtra($withABufferAfter));
    }

    public function test_a_slot_that_fills_its_gap_leaves_nothing_stranded_and_no_split(): void
    {
        $day = $this->dayOf([['09:00', '09:30']], $this->thirtySixtyNinety(), new ServiceTimes(30));

        $this->assertSame(['09:00' => [0, 10, 0, 10, 0]], $this->scores($day));
    }

    public function test_the_split_costs_5_minutes_only_when_a_slot_leaves_free_time_on_both_sides(): void
    {
        $day = $this->dayOf([['09:00', '10:00']], [new OfferedService(new ServiceTimes(30))], new ServiceTimes(30));

        $this->assertSame(
            ['09:00' => 0, '09:15' => 5, '09:30' => 0],
            array_map(fn (array $score): int => $score[2], $this->scores($day)),
        );
    }

    public function test_a_lost_room_of_exactly_a_half_rounds_up(): void
    {
        $day = $this->dayOf(
            [['09:00', '10:15']],
            [new OfferedService(new ServiceTimes(30), 1), new OfferedService(new ServiceTimes(45), 1)],
            new ServiceTimes(30),
        );

        $this->assertSame(38, $this->slotAt($day, '09:30')->waste->lostRoomMinutes);
    }

    public function test_a_lost_room_rounds_to_the_nearest_minute(): void
    {
        $day = $this->dayOf(
            [['09:00', '10:30']],
            [new OfferedService(new ServiceTimes(30), 1), new OfferedService(new ServiceTimes(20), 1), new OfferedService(new ServiceTimes(40), 1)],
            new ServiceTimes(30),
        );

        $this->assertSame(47, $this->slotAt($day, '09:15')->waste->lostRoomMinutes);
        $this->assertSame(43, $this->slotAt($day, '09:30')->waste->lostRoomMinutes);
    }

    public function test_extra_waste_compares_the_offered_times_only(): void
    {
        $day = $this->dayOf(
            [['09:00', '10:40']],
            $this->thirtySixtyNinety(),
            new ServiceTimes(30),
            now: '2026-11-09 09:00',
            minNoticeMinutes: 10,
        );

        $this->assertSame(
            ['09:15' => [90, 35], '09:30' => [65, 10], '09:45' => [100, 45], '10:00' => [55, 0]],
            $this->wasteAndExtra($day),
        );
    }

    public function test_extra_waste_compares_slots_in_the_same_gap_only(): void
    {
        $day = $this->dayOf([['09:00', '10:30'], ['14:00', '14:30']], $this->thirtySixtyNinety(), new ServiceTimes(30));

        $this->assertSame([10, 0], $this->wasteAndExtra($day)['14:00']);
        $this->assertSame([40, 0], $this->wasteAndExtra($day)['09:00']);
    }

    public function test_a_gap_too_short_for_every_service_is_stranded(): void
    {
        $offered = [new OfferedService(new ServiceTimes(45)), new OfferedService(new ServiceTimes(90))];

        $day = $this->dayOf([['09:00', '12:00']], $offered, new ServiceTimes(45), blocked: [['09:30', '12:00']]);

        $this->assertSame([['09:00', '09:30']], $this->ranges($day->gapSummary->stranded));
        $this->assertSame([], $day->gapSummary->fragmented);
        $this->assertSame(30, $day->gapSummary->strandedMinutes());
    }

    public function test_a_gap_that_fits_a_service_but_not_the_longest_weighted_one_is_fragmented(): void
    {
        $offered = [new OfferedService(new ServiceTimes(30)), new OfferedService(new ServiceTimes(120))];

        $day = $this->dayOf([['09:00', '10:30']], $offered, new ServiceTimes(30));

        $this->assertSame([], $day->gapSummary->stranded);
        $this->assertSame([['09:00', '10:30']], $this->ranges($day->gapSummary->fragmented));
        $this->assertSame(90, $day->gapSummary->fragmentedMinutes());
    }

    public function test_a_gap_that_fits_the_longest_weighted_service_is_usable(): void
    {
        $offered = [new OfferedService(new ServiceTimes(30)), new OfferedService(new ServiceTimes(120))];

        $day = $this->dayOf([['09:00', '11:00']], $offered, new ServiceTimes(30));

        $this->assertSame([[], []], [$day->gapSummary->stranded, $day->gapSummary->fragmented]);
    }

    public function test_a_gap_that_only_a_weight_0_service_fits_is_not_stranded(): void
    {
        $offered = [new OfferedService(new ServiceTimes(30), 0), new OfferedService(new ServiceTimes(60))];

        $day = $this->dayOf([['09:00', '09:30']], $offered, new ServiceTimes(30));

        $this->assertSame([], $day->gapSummary->stranded);
        $this->assertSame([['09:00', '09:30']], $this->ranges($day->gapSummary->fragmented));
    }

    public function test_a_longer_weight_0_service_does_not_count_as_the_longest(): void
    {
        $offered = [new OfferedService(new ServiceTimes(60)), new OfferedService(new ServiceTimes(120), 0)];

        $day = $this->dayOf([['09:00', '10:30']], $offered, new ServiceTimes(60));

        $this->assertSame([[], []], [$day->gapSummary->stranded, $day->gapSummary->fragmented]);
    }

    public function test_a_gap_is_usable_when_any_of_the_longest_weighted_services_fits(): void
    {
        $offered = [new OfferedService(new ServiceTimes(50, bufferBeforeMinutes: 10)), new OfferedService(new ServiceTimes(60))];

        $day = $this->dayOf([['09:00', '10:00']], $offered, new ServiceTimes(60));

        $this->assertSame([[], []], [$day->gapSummary->stranded, $day->gapSummary->fragmented]);
    }

    public function test_the_day_totals_each_kind_of_gap_in_time_order(): void
    {
        $offered = [new OfferedService(new ServiceTimes(30)), new OfferedService(new ServiceTimes(90))];

        $day = $this->dayOf([['09:00', '13:00']], $offered, new ServiceTimes(30), blocked: [['09:20', '10:00'], ['11:00', '12:40']]);

        $this->assertSame([['09:00', '09:20'], ['12:40', '13:00']], $this->ranges($day->gapSummary->stranded));
        $this->assertSame([['10:00', '11:00']], $this->ranges($day->gapSummary->fragmented));
        $this->assertSame([40, 60], [$day->gapSummary->strandedMinutes(), $day->gapSummary->fragmentedMinutes()]);
    }

    public function test_a_day_with_no_free_gaps_has_an_empty_summary(): void
    {
        $day = $this->dayOf([], $this->thirtySixtyNinety(), new ServiceTimes(30));

        $this->assertSame([[], [], 0, 0], [
            $day->gapSummary->stranded,
            $day->gapSummary->fragmented,
            $day->gapSummary->strandedMinutes(),
            $day->gapSummary->fragmentedMinutes(),
        ]);
    }

    /**
     * The overview's services: 30, 60 and 90 minutes with no buffers, each
     * with full room kept.
     *
     * @return list<OfferedService>
     */
    private function thirtySixtyNinety(): array
    {
        return [new OfferedService(new ServiceTimes(30)), new OfferedService(new ServiceTimes(60)), new OfferedService(new ServiceTimes(90))];
    }

    /**
     * The sample business's follow-up, assessment and 90-minute massage,
     * the massage weighted 5 as it's booked less often.
     *
     * @return list<OfferedService>
     */
    private function sampleServices(int $massageWeight = 5): array
    {
        return [new OfferedService(new ServiceTimes(30)), new OfferedService(new ServiceTimes(60)), new OfferedService(new ServiceTimes(90), $massageWeight)];
    }

    /**
     * Monday's diary, with its hours given as an edited day.
     *
     * @param  list<array{string, string}>  $hours
     * @param  list<OfferedService>  $offered
     * @param  list<array{string, string}>  $blocked  local times on the date
     */
    private function dayOf(
        array $hours,
        array $offered,
        ServiceTimes $searched,
        array $blocked = [],
        string $now = '2026-11-02 08:00',
        int $minNoticeMinutes = 0,
    ): DayAvailability {
        $blockedTimes = array_map(fn (array $range): TimeRange => new TimeRange(
            CarbonImmutable::parse(self::MONDAY." {$range[0]}", 'Europe/London'),
            CarbonImmutable::parse(self::MONDAY." {$range[1]}", 'Europe/London'),
        ), $blocked);

        $availability = (new SlotFinder)->find(
            new Diary(new WorkingHours([], [self::MONDAY => DayHours::fromArray($hours)]), $offered, $blockedTimes),
            $searched,
            new BookingSettings(new DateTimeZone('Europe/London'), 15, $minNoticeMinutes, 90, strictness: 0, relaxationHours: 48),
            DateRange::between(CarbonImmutable::parse(self::MONDAY), CarbonImmutable::parse(self::MONDAY)),
            CarbonImmutable::parse($now, 'Europe/London'),
        );

        $day = $availability->day(self::MONDAY);
        $this->assertNotNull($day);

        return $day;
    }

    /**
     * Each slot's stranded minutes, lost room, split, waste and extra
     * waste, by its local start time.
     *
     * @return array<string, array{int, int, int, int, int}>
     */
    private function scores(DayAvailability $day): array
    {
        $scores = [];

        foreach ($day->slots as $slot) {
            $waste = $slot->waste;
            $scores[$this->localTime($slot->startsAt)] = [
                $waste->strandedMinutes,
                $waste->lostRoomMinutes,
                $waste->splitMinutes,
                $waste->minutes,
                $waste->extraMinutes,
            ];
        }

        return $scores;
    }

    /**
     * Each slot's waste and extra waste, by its local start time.
     *
     * @return array<string, array{int, int}>
     */
    private function wasteAndExtra(DayAvailability $day): array
    {
        return array_map(fn (array $score): array => [$score[3], $score[4]], $this->scores($day));
    }

    private function slotAt(DayAvailability $day, string $time): Slot
    {
        foreach ($day->slots as $slot) {
            if ($this->localTime($slot->startsAt) === $time) {
                return $slot;
            }
        }

        $this->fail("No slot at {$time}.");
    }

    /**
     * The kinds of appointment a slot takes room from, as their lengths
     * and how many fewer fit.
     *
     * @return list<array{int, int}>
     */
    private function lostRoomOf(DayAvailability $day, string $time): array
    {
        return array_map(
            fn (array $kind): array => [$kind['times']->durationMinutes, $kind['fewer']],
            $this->slotAt($day, $time)->waste->lostRoom,
        );
    }

    private function localTime(CarbonImmutable $time): string
    {
        return $time->setTimezone('Europe/London')->format('H:i');
    }

    /**
     * @param  list<TimeRange>  $ranges
     * @return list<array{string, string}>
     */
    private function ranges(array $ranges): array
    {
        return array_map(fn (TimeRange $range): array => [$this->localTime($range->start), $this->localTime($range->end)], $ranges);
    }
}
