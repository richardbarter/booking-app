<?php

namespace Tests\Unit\Scheduling;

use App\Scheduling\Hours\DayHours;
use App\Scheduling\LocalClock;
use App\Scheduling\TimeRange;
use Carbon\CarbonImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Times in these tests are written in UTC unless a name says otherwise.
 * London's clocks go forward at 01:00 UTC on 28 March 2027 and back at
 * 01:00 UTC on 31 October 2027.
 */
class LocalClockTest extends TestCase
{
    public function test_turns_local_hours_into_utc_in_winter(): void
    {
        $times = $this->clock('Europe/London')->workingTimes($this->date('2026-11-09'), $this->hours([['09:00', '13:00'], ['14:00', '17:30']]));

        $this->assertSame([
            ['2026-11-09 09:00', '2026-11-09 13:00'],
            ['2026-11-09 14:00', '2026-11-09 17:30'],
        ], $this->inUtc($times));
    }

    public function test_turns_local_hours_into_utc_in_summer(): void
    {
        $times = $this->clock('Europe/London')->workingTimes($this->date('2026-07-06'), $this->hours([['09:00', '13:00'], ['14:00', '17:30']]));

        $this->assertSame([
            ['2026-07-06 08:00', '2026-07-06 12:00'],
            ['2026-07-06 13:00', '2026-07-06 16:30'],
        ], $this->inUtc($times));
    }

    public function test_24_00_means_the_first_moment_of_the_next_date(): void
    {
        $times = $this->clock('Europe/London')->workingTimes($this->date('2026-11-09'), $this->hours([['18:00', '24:00']]));

        $this->assertSame([['2026-11-09 18:00', '2026-11-10 00:00']], $this->inUtc($times));
    }

    public function test_reads_the_calendar_date_in_the_zone_the_date_is_given_in(): void
    {
        $date = CarbonImmutable::parse('2026-11-09 23:00', 'America/New_York');

        $times = $this->clock('Europe/London')->workingTimes($date, $this->hours([['09:00', '10:00']]));

        $this->assertSame([['2026-11-09 09:00', '2026-11-09 10:00']], $this->inUtc($times));
    }

    public function test_a_day_with_no_hours_has_no_working_times(): void
    {
        $this->assertSame([], $this->clock('Europe/London')->workingTimes($this->date('2026-11-09'), new DayHours));
    }

    public function test_clamps_hours_ending_in_the_skipped_hour_to_the_moment_the_clocks_go_forward(): void
    {
        $times = $this->clock('Europe/London')->workingTimes($this->date('2027-03-28'), $this->hours([['00:30', '01:30']]));

        $this->assertSame([['2027-03-28 00:30', '2027-03-28 01:00']], $this->inUtc($times));
    }

    public function test_clamps_hours_starting_in_the_skipped_hour_to_the_moment_the_clocks_go_forward(): void
    {
        $times = $this->clock('Europe/London')->workingTimes($this->date('2027-03-28'), $this->hours([['01:30', '03:00']]));

        $this->assertSame([['2027-03-28 01:00', '2027-03-28 02:00']], $this->inUtc($times));
    }

    public function test_drops_a_range_wholly_inside_the_skipped_hour_and_keeps_the_hours_either_side(): void
    {
        $times = $this->clock('Europe/London')->workingTimes(
            $this->date('2027-03-28'),
            $this->hours([['00:00', '00:45'], ['01:00', '01:45'], ['09:00', '17:00']]),
        );

        $this->assertSame([
            ['2027-03-28 00:00', '2027-03-28 00:45'],
            ['2027-03-28 08:00', '2027-03-28 16:00'],
        ], $this->inUtc($times));
    }

    public function test_joins_hours_either_side_of_a_break_inside_the_skipped_hour(): void
    {
        $times = $this->clock('Europe/London')->workingTimes(
            $this->date('2027-03-28'),
            $this->hours([['00:00', '01:15'], ['01:30', '03:00'], ['09:00', '17:00']]),
        );

        $this->assertSame([
            ['2027-03-28 00:00', '2027-03-28 02:00'],
            ['2027-03-28 08:00', '2027-03-28 16:00'],
        ], $this->inUtc($times));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function skippedClockTimes(): array
    {
        return ['01:00' => [60], '01:15' => [75], '01:30' => [90], '01:45' => [105]];
    }

    #[DataProvider('skippedClockTimes')]
    public function test_gives_no_start_time_for_a_clock_time_the_clocks_skip(int $minuteOfDay): void
    {
        $this->assertNull($this->clock('Europe/London')->startTimeOn($this->date('2027-03-28'), $minuteOfDay));
    }

    public function test_lists_no_start_times_in_the_skipped_hour(): void
    {
        $startTimes = $this->clock('Europe/London')->startTimesOn($this->date('2027-03-28'), 15);

        $this->assertCount(92, $startTimes);
        $this->assertSame(
            ['2027-03-28 00:30', '2027-03-28 00:45', '2027-03-28 01:00', '2027-03-28 01:15'],
            $this->instantsInUtc(array_slice($startTimes, 2, 4)),
        );
    }

    public function test_takes_the_first_occurrence_of_an_end_in_the_repeated_hour(): void
    {
        $times = $this->clock('Europe/London')->workingTimes($this->date('2027-10-31'), $this->hours([['00:00', '01:30']]));

        $this->assertSame([['2027-10-30 23:00', '2027-10-31 00:30']], $this->inUtc($times));
    }

    public function test_takes_the_first_occurrence_of_a_start_in_the_repeated_hour(): void
    {
        $times = $this->clock('Europe/London')->workingTimes($this->date('2027-10-31'), $this->hours([['01:30', '03:00']]));

        $this->assertSame([['2027-10-31 00:30', '2027-10-31 03:00']], $this->inUtc($times));
    }

    public function test_hours_after_the_repeated_hour_are_unaffected(): void
    {
        $times = $this->clock('Europe/London')->workingTimes($this->date('2027-10-31'), $this->hours([['02:00', '03:00']]));

        $this->assertSame([['2027-10-31 02:00', '2027-10-31 03:00']], $this->inUtc($times));
    }

    public function test_a_range_across_the_night_the_clocks_go_back_is_an_hour_longer(): void
    {
        $times = $this->clock('Europe/London')->workingTimes($this->date('2027-10-31'), $this->hours([['00:00', '03:00']]));

        $this->assertSame([['2027-10-30 23:00', '2027-10-31 03:00']], $this->inUtc($times));
        $this->assertSame(240.0, $times[0]->start->diffInMinutes($times[0]->end));
    }

    public function test_gives_only_the_first_occurrence_of_a_repeated_start_time(): void
    {
        $clock = $this->clock('Europe/London');

        $startTime = $clock->startTimeOn($this->date('2027-10-31'), 75);
        $startTimes = $clock->startTimesOn($this->date('2027-10-31'), 15);

        $this->assertSame('2027-10-31 00:15', $startTime?->format('Y-m-d H:i'));
        $this->assertCount(96, $startTimes);
        $this->assertSame(
            ['2027-10-31 00:30', '2027-10-31 00:45', '2027-10-31 02:00'],
            $this->instantsInUtc(array_slice($startTimes, 6, 3)),
        );
    }

    public function test_a_day_runs_from_midnight_to_midnight_in_the_business_zone(): void
    {
        $clock = $this->clock('Europe/London');

        $this->assertSame(['2026-11-09 00:00', '2026-11-10 00:00'], $this->inUtc([$clock->day($this->date('2026-11-09'))])[0]);
        $this->assertSame(['2026-07-05 23:00', '2026-07-06 23:00'], $this->inUtc([$clock->day($this->date('2026-07-06'))])[0]);
    }

    public function test_the_days_the_clocks_change_are_23_and_25_hours_long(): void
    {
        $clock = $this->clock('Europe/London');

        $this->assertSame(['2027-03-28 00:00', '2027-03-28 23:00'], $this->inUtc([$clock->day($this->date('2027-03-28'))])[0]);
        $this->assertSame(['2027-10-30 23:00', '2027-11-01 00:00'], $this->inUtc([$clock->day($this->date('2027-10-31'))])[0]);
    }

    public function test_hours_from_midnight_start_at_01_00_when_chile_skips_midnight(): void
    {
        $times = $this->clock('America/Santiago')->workingTimes($this->date('2026-09-06'), $this->hours([['00:00', '03:00']]));

        $this->assertSame([['2026-09-06 04:00', '2026-09-06 06:00']], $this->inUtc($times));
    }

    public function test_a_day_starts_at_01_00_when_chile_skips_midnight(): void
    {
        $day = $this->clock('America/Santiago')->day($this->date('2026-09-06'));

        $this->assertSame(['2026-09-06 04:00', '2026-09-07 03:00'], $this->inUtc([$day])[0]);
    }

    public function test_24_00_on_the_day_before_chile_skips_midnight_is_that_same_moment(): void
    {
        $clock = $this->clock('America/Santiago');

        $times = $clock->workingTimes($this->date('2026-09-05'), $this->hours([['20:00', '24:00']]));

        $this->assertSame([['2026-09-06 00:00', '2026-09-06 04:00']], $this->inUtc($times));
        $this->assertSame('2026-09-06 04:00', $clock->day($this->date('2026-09-05'))->end->format('Y-m-d H:i'));
    }

    public function test_the_day_before_chile_goes_back_at_midnight_is_25_hours_and_repeats_its_last_hour(): void
    {
        $clock = $this->clock('America/Santiago');

        $day = $clock->day($this->date('2027-04-03'));
        $startTime = $clock->startTimeOn($this->date('2027-04-03'), 23 * 60 + 30);

        $this->assertSame(['2027-04-03 03:00', '2027-04-04 04:00'], $this->inUtc([$day])[0]);
        $this->assertSame('2027-04-04 02:30', $startTime?->format('Y-m-d H:i'));
    }

    public function test_lord_howe_island_skips_exactly_02_00_to_02_29(): void
    {
        $clock = $this->clock('Australia/Lord_Howe');
        $date = $this->date('2026-10-04');

        $startTimes = $clock->startTimesOn($date, 5);
        $localTimes = array_map(fn (CarbonImmutable $time): string => $time->setTimezone('Australia/Lord_Howe')->format('H:i'), $startTimes);

        $this->assertCount(282, $startTimes);
        $this->assertSame(['01:50', '01:55', '02:30', '02:35'], array_slice($localTimes, 22, 4));
        $this->assertSame('2026-10-03 15:25', $clock->startTimeOn($date, 115)?->format('Y-m-d H:i'));
        $this->assertNull($clock->startTimeOn($date, 120));
        $this->assertNull($clock->startTimeOn($date, 145));
        $this->assertSame('2026-10-03 15:30', $clock->startTimeOn($date, 150)?->format('Y-m-d H:i'));
    }

    public function test_09_00_in_kathmandu_is_03_15_utc(): void
    {
        $clock = $this->clock('Asia/Kathmandu');

        $times = $clock->workingTimes($this->date('2026-11-09'), $this->hours([['09:00', '17:00']]));

        $this->assertSame([['2026-11-09 03:15', '2026-11-09 11:15']], $this->inUtc($times));
        $this->assertSame('2026-11-09 03:15', $clock->startTimeOn($this->date('2026-11-09'), 540)?->format('Y-m-d H:i'));
    }

    public function test_lists_every_clock_time_on_the_interval_on_a_normal_day(): void
    {
        $clock = $this->clock('Europe/London');

        $everyQuarter = $clock->startTimesOn($this->date('2026-11-09'), 15);
        $everyMinute = $clock->startTimesOn($this->date('2026-11-09'), 1);

        $this->assertCount(96, $everyQuarter);
        $this->assertSame('2026-11-09 00:00', $everyQuarter[0]->format('Y-m-d H:i'));
        $this->assertSame('2026-11-09 23:45', $everyQuarter[95]->format('Y-m-d H:i'));
        $this->assertCount(1440, $everyMinute);
    }

    public function test_rejects_an_interval_below_one_minute(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least 1 minute, got 0');

        $this->clock('Europe/London')->startTimesOn($this->date('2026-11-09'), 0);
    }

    public function test_gives_start_times_at_the_first_and_last_minute_of_the_day(): void
    {
        $clock = $this->clock('Europe/London');

        $this->assertSame('2026-11-09 00:00', $clock->startTimeOn($this->date('2026-11-09'), 0)?->format('Y-m-d H:i'));
        $this->assertSame('2026-11-09 23:59', $clock->startTimeOn($this->date('2026-11-09'), 1439)?->format('Y-m-d H:i'));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function minutesOutsideTheDay(): array
    {
        return ['before midnight' => [-1], 'the next midnight' => [1440]];
    }

    #[DataProvider('minutesOutsideTheDay')]
    public function test_rejects_a_start_time_outside_the_day(int $minuteOfDay): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('from 0 to 1439 minutes after midnight');

        $this->clock('Europe/London')->startTimeOn($this->date('2026-11-09'), $minuteOfDay);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function localDates(): array
    {
        return [
            'just after midnight in a London summer, still the day before in UTC' => ['Europe/London', '2026-07-06 23:30', '2026-07-07'],
            'a London evening in summer' => ['Europe/London', '2026-07-06 22:30', '2026-07-06'],
            'an Auckland morning, a day ahead of UTC' => ['Pacific/Auckland', '2026-11-09 20:00', '2026-11-10'],
        ];
    }

    #[DataProvider('localDates')]
    public function test_gives_the_local_date_of_an_instant(string $zone, string $utc, string $expected): void
    {
        $this->assertSame($expected, $this->clock($zone)->dateOf(CarbonImmutable::parse($utc, 'UTC')));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function zonesThatAreNotNamed(): array
    {
        return ['a fixed offset' => ['+05:45'], 'an abbreviation' => ['BST']];
    }

    #[DataProvider('zonesThatAreNotNamed')]
    public function test_rejects_a_zone_that_is_not_a_named_zone(string $zone): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("must be a named zone such as Europe/London, got {$zone}");

        $this->clock($zone);
    }

    private function clock(string $zone): LocalClock
    {
        return new LocalClock(new DateTimeZone($zone));
    }

    private function date(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'UTC');
    }

    /**
     * @param  list<array{string, string}>  $ranges
     */
    private function hours(array $ranges): DayHours
    {
        return DayHours::fromArray($ranges);
    }

    /**
     * @param  list<TimeRange>  $ranges
     * @return list<array{string, string}>
     */
    private function inUtc(array $ranges): array
    {
        return array_map(fn (TimeRange $range): array => [
            $range->start->format('Y-m-d H:i'),
            $range->end->format('Y-m-d H:i'),
        ], $ranges);
    }

    /**
     * @param  list<CarbonImmutable>  $instants
     * @return list<string>
     */
    private function instantsInUtc(array $instants): array
    {
        return array_map(fn (CarbonImmutable $instant): string => $instant->format('Y-m-d H:i'), $instants);
    }
}
