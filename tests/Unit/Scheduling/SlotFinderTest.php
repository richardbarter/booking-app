<?php

namespace Tests\Unit\Scheduling;

use App\Scheduling\Availability;
use App\Scheduling\BookingSettings;
use App\Scheduling\DateRange;
use App\Scheduling\DayAvailability;
use App\Scheduling\Diary;
use App\Scheduling\Hours\DayHours;
use App\Scheduling\Hours\PatternWeeks;
use App\Scheduling\Hours\RepeatingPattern;
use App\Scheduling\Hours\WorkingHours;
use App\Scheduling\ServiceTimes;
use App\Scheduling\Slot;
use App\Scheduling\SlotFinder;
use App\Scheduling\TimeRange;
use Carbon\CarbonImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Worked examples, in London on Monday 9 November 2026 (when London is on
 * UTC) unless a test says otherwise. Times are local.
 */
class SlotFinderTest extends TestCase
{
    private const string MONDAY = '2026-11-09';

    public function test_offers_the_overviews_example(): void
    {
        $availability = $this->findOn([['09:00', '12:00']], new ServiceTimes(60), blocked: [['10:00', '10:30']]);

        $this->assertSame(['09:00', '10:30', '10:45', '11:00'], $this->offered($availability));
    }

    public function test_a_buffer_before_moves_the_first_time_after_the_start_of_the_hours(): void
    {
        $availability = $this->findOn([['09:00', '12:00']], new ServiceTimes(60, bufferBeforeMinutes: 15));

        $offered = $this->offered($availability);
        $this->assertSame('09:15', $offered[0]);
        $this->assertSame('11:00', $offered[array_key_last($offered)]);
    }

    public function test_a_buffer_after_keeps_the_last_time_inside_the_hours(): void
    {
        $availability = $this->findOn([['09:00', '12:00']], new ServiceTimes(60, bufferAfterMinutes: 15));

        $offered = $this->offered($availability);
        $this->assertSame('09:00', $offered[0]);
        $this->assertSame('10:45', $offered[array_key_last($offered)]);
    }

    public function test_buffers_cannot_use_the_lunch_break(): void
    {
        $availability = $this->findOn([['09:00', '13:00'], ['14:00', '17:00']], new ServiceTimes(60, 15, 15));

        $this->assertSame([
            '09:15', '09:30', '09:45', '10:00', '10:15', '10:30', '10:45', '11:00', '11:15', '11:30', '11:45',
            '14:15', '14:30', '14:45', '15:00', '15:15', '15:30', '15:45',
        ], $this->offered($availability));
    }

    public function test_back_to_back_a_buffer_before_starts_after_the_blocked_time(): void
    {
        $availability = $this->findOn([['09:00', '12:00']], new ServiceTimes(30, bufferBeforeMinutes: 15), blocked: [['09:00', '10:15']]);

        $this->assertSame('10:30', $this->offered($availability)[0]);
    }

    public function test_a_gap_off_the_grid_offers_the_next_time_on_the_interval(): void
    {
        $everyQuarter = $this->findOn([['09:00', '12:00']], new ServiceTimes(30), blocked: [['09:00', '10:07']]);
        $everyTen = $this->findOn([['09:00', '12:00']], new ServiceTimes(30), blocked: [['09:00', '10:07']], intervalMinutes: 10);

        $this->assertSame('10:15', $this->offered($everyQuarter)[0]);
        $this->assertSame('10:10', $this->offered($everyTen)[0]);
    }

    public function test_blocked_time_ending_part_way_through_a_second_keeps_that_second_from_being_booked(): void
    {
        $blocked = new TimeRange(CarbonImmutable::parse('2026-11-09 09:00', 'UTC'), CarbonImmutable::parse('2026-11-09 10:00:00.5', 'UTC'));

        $availability = (new SlotFinder)->find(
            new Diary(new WorkingHours([], [self::MONDAY => DayHours::fromArray([['09:00', '11:00']])]), [$blocked]),
            new ServiceTimes(30),
            $this->settings(),
            $this->dates(self::MONDAY, self::MONDAY),
            $this->now(),
        );

        $this->assertSame(['10:15', '10:30'], $this->offered($availability));
    }

    /**
     * @return array<string, array{int, list<array{string, string}>, list<string>}>
     */
    public static function slotIntervals(): array
    {
        return [
            '10 minutes' => [10, [['09:00', '11:00']], ['09:00', '09:10', '09:20', '09:30', '09:40', '09:50', '10:00', '10:10', '10:20', '10:30']],
            '20 minutes' => [20, [['09:00', '11:00']], ['09:00', '09:20', '09:40', '10:00', '10:20']],
            '30 minutes' => [30, [['09:00', '11:00']], ['09:00', '09:30', '10:00', '10:30']],
            '60 minutes' => [60, [['09:00', '11:00']], ['09:00', '10:00']],
            '60 minutes, with hours from half past' => [60, [['09:30', '11:30']], ['10:00', '11:00']],
        ];
    }

    /**
     * @param  list<array{string, string}>  $hours
     * @param  list<string>  $expected
     */
    #[DataProvider('slotIntervals')]
    public function test_offers_times_on_the_slot_intervals_grid(int $intervalMinutes, array $hours, array $expected): void
    {
        $availability = $this->findOn($hours, new ServiceTimes(30), intervalMinutes: $intervalMinutes);

        $this->assertSame($expected, $this->offered($availability));
    }

    public function test_start_times_are_on_the_local_clock_in_kathmandu(): void
    {
        $availability = $this->findOn([['09:00', '10:00']], new ServiceTimes(30), zone: 'Asia/Kathmandu');

        $this->assertSame(['09:00', '09:15', '09:30'], $this->offered($availability, 'Asia/Kathmandu'));
        $this->assertSame('2026-11-09 03:15', $this->day($availability)->slots[0]->startsAt->format('Y-m-d H:i'));
    }

    public function test_a_date_with_no_pattern_in_force_has_no_working_times_free_gaps_or_slots(): void
    {
        $availability = (new SlotFinder)->find(
            new Diary(new WorkingHours([], [])),
            new ServiceTimes(30),
            $this->settings(),
            $this->dates(self::MONDAY, self::MONDAY),
            $this->now(),
        );

        $day = $this->day($availability);
        $this->assertSame([[], [], []], [$day->workingTimes, $day->freeGaps, $day->slots]);
    }

    public function test_an_edited_day_with_no_hours_has_no_working_times_free_gaps_or_slots(): void
    {
        $hours = new WorkingHours([$this->everyDay([['09:00', '17:00']])], [self::MONDAY => new DayHours]);

        $availability = (new SlotFinder)->find(new Diary($hours), new ServiceTimes(30), $this->settings(), $this->dates(self::MONDAY, self::MONDAY), $this->now());

        $day = $this->day($availability);
        $this->assertSame([[], [], []], [$day->workingTimes, $day->freeGaps, $day->slots]);
    }

    public function test_a_service_longer_than_every_gap_offers_nothing_but_the_day_keeps_its_gaps(): void
    {
        $availability = $this->findOn([['09:00', '10:00'], ['11:00', '12:00']], new ServiceTimes(90));

        $day = $this->day($availability);
        $this->assertSame([], $day->slots);
        $this->assertSame([['09:00', '10:00'], ['11:00', '12:00']], $this->ranges($day->freeGaps));
    }

    public function test_does_not_offer_todays_times_inside_the_minimum_notice(): void
    {
        $availability = $this->findOn([['09:00', '12:00']], new ServiceTimes(30), minNoticeMinutes: 60, now: '2026-11-09 09:40');

        $this->assertSame(['10:45', '11:00', '11:15', '11:30'], $this->offered($availability));
    }

    public function test_dates_after_the_last_date_have_no_slots_but_keep_their_working_times_and_gaps(): void
    {
        $availability = (new SlotFinder)->find(
            new Diary(new WorkingHours([$this->everyDay([['09:00', '12:00']])], [])),
            new ServiceTimes(30),
            $this->settings(maxAdvanceDays: 1),
            $this->dates('2026-11-03', '2026-11-04'),
            $this->now('2026-11-02 08:00'),
        );

        $lastDate = $this->day($availability, '2026-11-03');
        $dateAfter = $this->day($availability, '2026-11-04');
        $this->assertCount(11, $lastDate->slots);
        $this->assertSame([], $dateAfter->slots);
        $this->assertSame([['09:00', '12:00']], $this->ranges($dateAfter->workingTimes));
        $this->assertSame([['09:00', '12:00']], $this->ranges($dateAfter->freeGaps));
    }

    public function test_past_dates_have_no_slots(): void
    {
        $availability = $this->findOn([['09:00', '12:00']], new ServiceTimes(30), now: '2026-11-10 08:00');

        $day = $this->day($availability);
        $this->assertSame([], $day->slots);
        $this->assertSame([['09:00', '12:00']], $this->ranges($day->workingTimes));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function clockChanges(): array
    {
        return [
            'forward' => ['2027-03-27', '2027-03-29', '2027-03-01 08:00'],
            'back' => ['2027-10-30', '2027-11-01', '2027-10-01 08:00'],
        ];
    }

    #[DataProvider('clockChanges')]
    public function test_a_normal_day_offers_the_same_local_times_either_side_of_a_clock_change(string $from, string $to, string $now): void
    {
        $availability = (new SlotFinder)->find(
            new Diary(new WorkingHours([$this->everyDay([['09:00', '17:00']])], [])),
            new ServiceTimes(60),
            $this->settings(),
            $this->dates($from, $to),
            $this->now($now),
        );

        $expected = ['09:00', '09:15', '09:30', '09:45', '10:00', '10:15', '10:30', '10:45', '11:00', '11:15', '11:30', '11:45',
            '12:00', '12:15', '12:30', '12:45', '13:00', '13:15', '13:30', '13:45', '14:00', '14:15', '14:30', '14:45',
            '15:00', '15:15', '15:30', '15:45', '16:00'];
        foreach ($availability->days as $day) {
            $this->assertSame($expected, $this->localTimes($day->slots), $day->date);
        }
    }

    public function test_a_slot_across_the_skipped_hour_lasts_its_real_length(): void
    {
        $availability = $this->findOn([['00:00', '04:00']], new ServiceTimes(60), date: '2027-03-28', now: '2027-03-01 08:00');

        $slots = $this->day($availability, '2027-03-28')->slots;
        $this->assertSame(
            ['00:00', '00:15', '00:30', '00:45', '02:00', '02:15', '02:30', '02:45', '03:00'],
            $this->localTimes($slots),
        );
        $this->assertSame('02:30', $slots[2]->endsAt->setTimezone('Europe/London')->format('H:i'));
        $this->assertSame('2027-03-28 01:30', $slots[2]->endsAt->format('Y-m-d H:i'));
    }

    public function test_offers_a_repeated_clock_time_once(): void
    {
        $availability = $this->findOn([['00:00', '03:00']], new ServiceTimes(30), date: '2027-10-31', now: '2027-10-01 08:00');

        $this->assertSame(
            ['00:00', '00:15', '00:30', '00:45', '01:00', '01:15', '01:30', '01:45', '02:00', '02:15', '02:30'],
            $this->localTimes($this->day($availability, '2027-10-31')->slots),
        );
    }

    public function test_each_slot_gives_its_end_its_blocked_time_and_its_gap(): void
    {
        $availability = $this->findOn([['09:00', '12:00']], new ServiceTimes(30, 10, 5), blocked: [['10:00', '10:30']]);

        $slots = $this->day($availability)->slots;
        $this->assertSame(['09:15', '10:45', '11:00', '11:15'], $this->localTimes($slots));
        $this->assertSame(
            ['09:15', '09:45', '09:05', '09:50', '09:00', '10:00'],
            $this->fieldsOf($slots[0]),
        );
        $this->assertSame(
            ['11:00', '11:30', '10:50', '11:35', '10:30', '12:00'],
            $this->fieldsOf($slots[2]),
        );
    }

    public function test_gives_one_day_for_each_date_in_order(): void
    {
        $availability = (new SlotFinder)->find(
            new Diary(new WorkingHours([], [self::MONDAY => DayHours::fromArray([['09:00', '10:00']])])),
            new ServiceTimes(30),
            $this->settings(),
            $this->dates('2026-11-08', '2026-11-10'),
            $this->now(),
        );

        $this->assertSame(['2026-11-08', '2026-11-09', '2026-11-10'], array_map(fn (DayAvailability $day): string => $day->date, $availability->days));
        $this->assertSame([0, 3, 0], array_map(fn (DayAvailability $day): int => count($day->slots), $availability->days));
    }

    /**
     * Find a date's slots, with the date's hours given as an edited day.
     *
     * @param  list<array{string, string}>  $hours
     * @param  list<array{string, string}>  $blocked  local times on the date
     */
    private function findOn(
        array $hours,
        ServiceTimes $service,
        array $blocked = [],
        int $intervalMinutes = 15,
        int $minNoticeMinutes = 0,
        string $now = '2026-11-02 08:00',
        string $date = self::MONDAY,
        string $zone = 'Europe/London',
    ): Availability {
        $blockedTimes = array_map(fn (array $range): TimeRange => new TimeRange(
            CarbonImmutable::parse("{$date} {$range[0]}", $zone),
            CarbonImmutable::parse("{$date} {$range[1]}", $zone),
        ), $blocked);

        return (new SlotFinder)->find(
            new Diary(new WorkingHours([], [$date => DayHours::fromArray($hours)]), $blockedTimes),
            $service,
            $this->settings($intervalMinutes, $minNoticeMinutes, zone: $zone),
            $this->dates($date, $date),
            $this->now($now, $zone),
        );
    }

    private function settings(int $intervalMinutes = 15, int $minNoticeMinutes = 0, int $maxAdvanceDays = 90, string $zone = 'Europe/London'): BookingSettings
    {
        return new BookingSettings(new DateTimeZone($zone), $intervalMinutes, $minNoticeMinutes, $maxAdvanceDays);
    }

    private function dates(string $from, string $to): DateRange
    {
        return DateRange::between(CarbonImmutable::parse($from), CarbonImmutable::parse($to));
    }

    private function now(string $time = '2026-11-02 08:00', string $zone = 'Europe/London'): CarbonImmutable
    {
        return CarbonImmutable::parse($time, $zone);
    }

    /**
     * A weekly pattern, working the same hours every day, from long before
     * the tests' dates.
     *
     * @param  list<array{string, string}>  $hours
     */
    private function everyDay(array $hours): RepeatingPattern
    {
        $week = array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], $hours);

        return new RepeatingPattern(
            CarbonImmutable::parse('2026-01-05'),
            null,
            CarbonImmutable::parse('2026-01-05'),
            PatternWeeks::fromArray([$week]),
        );
    }

    private function day(Availability $availability, string $date = self::MONDAY): DayAvailability
    {
        $day = $availability->day($date);
        $this->assertNotNull($day);

        return $day;
    }

    /**
     * @return list<string>
     */
    private function offered(Availability $availability, string $zone = 'Europe/London'): array
    {
        return $this->localTimes($this->day($availability)->slots, $zone);
    }

    /**
     * @param  list<Slot>  $slots
     * @return list<string>
     */
    private function localTimes(array $slots, string $zone = 'Europe/London'): array
    {
        return array_map(fn (Slot $slot): string => $slot->startsAt->setTimezone($zone)->format('H:i'), $slots);
    }

    /**
     * @param  list<TimeRange>  $ranges
     * @return list<array{string, string}>
     */
    private function ranges(array $ranges): array
    {
        return array_map(fn (TimeRange $range): array => [
            $range->start->setTimezone('Europe/London')->format('H:i'),
            $range->end->setTimezone('Europe/London')->format('H:i'),
        ], $ranges);
    }

    /**
     * The slot's start, end, blocked from, blocked until, and gap, in local time.
     *
     * @return list<string>
     */
    private function fieldsOf(Slot $slot): array
    {
        return array_map(
            fn (CarbonImmutable $time): string => $time->setTimezone('Europe/London')->format('H:i'),
            [$slot->startsAt, $slot->endsAt, $slot->blockedFrom, $slot->blockedUntil, $slot->gap->start, $slot->gap->end],
        );
    }
}
