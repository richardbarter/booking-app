<?php

namespace Tests\Unit\Scheduling\Hours;

use App\Scheduling\Hours\PatternWeeks;
use App\Scheduling\Hours\RepeatingPattern;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RepeatingPatternTest extends TestCase
{
    public function test_covers_its_first_and_last_dates(): void
    {
        $pattern = $this->pattern('2026-11-04', '2026-11-20', '2026-11-02', [['mon' => [['09:00', '17:00']]]]);

        $this->assertFalse($pattern->covers(CarbonImmutable::parse('2026-11-03')));
        $this->assertTrue($pattern->covers(CarbonImmutable::parse('2026-11-04')));
        $this->assertTrue($pattern->covers(CarbonImmutable::parse('2026-11-20')));
        $this->assertFalse($pattern->covers(CarbonImmutable::parse('2026-11-21')));
    }

    public function test_with_no_end_date_covers_every_date_from_its_start(): void
    {
        $pattern = $this->pattern('2026-11-02', null, '2026-11-02', [['mon' => [['09:00', '17:00']]]]);

        $this->assertFalse($pattern->covers(CarbonImmutable::parse('2026-11-01')));
        $this->assertTrue($pattern->covers(CarbonImmutable::parse('2026-11-02')));
        $this->assertTrue($pattern->covers(CarbonImmutable::parse('2099-12-31')));
    }

    public function test_a_weekly_pattern_gives_the_same_hours_every_week(): void
    {
        $pattern = $this->pattern('2026-11-02', null, '2026-11-02', [
            ['mon' => [['09:00', '17:00']], 'tue' => [['10:00', '16:00']]],
        ]);

        $this->assertSame([['09:00', '17:00']], $this->hoursOn($pattern, '2026-11-02'));
        $this->assertSame([['10:00', '16:00']], $this->hoursOn($pattern, '2026-11-10'));
        $this->assertSame([['09:00', '17:00']], $this->hoursOn($pattern, '2027-03-01'));
        $this->assertSame([], $this->hoursOn($pattern, '2026-11-08'));
    }

    public function test_a_fortnightly_patterns_weeks_alternate(): void
    {
        $pattern = $this->pattern('2026-11-02', null, '2026-11-02', [
            ['mon' => [['09:00', '13:00']]],
            ['mon' => [['14:00', '18:00']], 'fri' => [['09:00', '12:00']]],
        ]);

        $this->assertSame([['09:00', '13:00']], $this->hoursOn($pattern, '2026-11-02'));
        $this->assertSame([], $this->hoursOn($pattern, '2026-11-06'));
        $this->assertSame([['14:00', '18:00']], $this->hoursOn($pattern, '2026-11-09'));
        $this->assertSame([['09:00', '12:00']], $this->hoursOn($pattern, '2026-11-13'));
        $this->assertSame([['09:00', '13:00']], $this->hoursOn($pattern, '2026-11-16'));
        $this->assertSame([['14:00', '18:00']], $this->hoursOn($pattern, '2026-11-23'));
    }

    public function test_a_4_week_cycle_wraps_around_to_week_1(): void
    {
        $pattern = $this->pattern('2026-11-02', null, '2026-11-02', [
            ['mon' => [['01:00', '02:00']]],
            ['mon' => [['02:00', '03:00']]],
            ['mon' => [['03:00', '04:00']]],
            ['mon' => [['04:00', '05:00']]],
        ]);

        $this->assertSame([['01:00', '02:00']], $this->hoursOn($pattern, '2026-11-02'));
        $this->assertSame([['02:00', '03:00']], $this->hoursOn($pattern, '2026-11-09'));
        $this->assertSame([['03:00', '04:00']], $this->hoursOn($pattern, '2026-11-16'));
        $this->assertSame([['04:00', '05:00']], $this->hoursOn($pattern, '2026-11-23'));
        $this->assertSame([['01:00', '02:00']], $this->hoursOn($pattern, '2026-11-30'));
    }

    public function test_a_pattern_starting_on_a_wednesday_counts_its_weeks_from_that_weeks_monday(): void
    {
        $pattern = $this->pattern('2026-11-04', null, '2026-11-02', [
            ['mon' => [['09:00', '13:00']], 'thu' => [['10:00', '14:00']]],
            ['mon' => [['14:00', '18:00']]],
        ]);

        $this->assertSame([['10:00', '14:00']], $this->hoursOn($pattern, '2026-11-05'));
        $this->assertSame([['14:00', '18:00']], $this->hoursOn($pattern, '2026-11-09'));
    }

    public function test_a_resumed_pattern_keeps_the_rhythm_of_its_earlier_cycle(): void
    {
        $weeks = [
            ['mon' => [['09:00', '13:00']], 'tue' => [['09:00', '13:00']]],
            ['mon' => [['14:00', '18:00']], 'tue' => [['14:00', '18:00']]],
        ];
        $resumed = $this->pattern('2026-09-01', null, '2026-01-05', $weeks);

        $this->assertSame([['09:00', '13:00']], $this->hoursOn($resumed, '2026-09-01'));
        $this->assertSame([['14:00', '18:00']], $this->hoursOn($resumed, '2026-09-07'));
        $this->assertSame([['09:00', '13:00']], $this->hoursOn($resumed, '2026-09-14'));
    }

    /**
     * @return array<string, array{string, string, list<array{string, string}>}>
     */
    public static function datesEitherSideOfALondonClockChange(): array
    {
        return [
            'the Saturday before the clocks go forward' => ['2026-03-23', '2026-03-28', [['09:00', '13:00']]],
            'the Monday after the clocks go forward' => ['2026-03-23', '2026-03-30', [['09:00', '17:00']]],
            'the Saturday before the clocks go back' => ['2026-10-19', '2026-10-24', [['09:00', '13:00']]],
            'the Monday after the clocks go back' => ['2026-10-19', '2026-10-26', [['09:00', '17:00']]],
        ];
    }

    /**
     * The dates are given at midnight London time, which in summer is the
     * evening before in UTC. Saturdays are in week 1 and Mondays in week 2.
     *
     * @param  list<array{string, string}>  $expectedHours
     */
    #[DataProvider('datesEitherSideOfALondonClockChange')]
    public function test_gives_the_same_local_hours_either_side_of_a_clock_change(string $cycleStartsOn, string $date, array $expectedHours): void
    {
        $pattern = $this->pattern($cycleStartsOn, null, $cycleStartsOn, [
            ['sat' => [['09:00', '13:00']]],
            ['mon' => [['09:00', '17:00']]],
        ]);

        $hours = $pattern->hoursOn(CarbonImmutable::parse($date, 'Europe/London'))->toArray();

        $this->assertSame($expectedHours, $hours);
    }

    /**
     * @return array<string, array{string, string, list<array{string, string}>}>
     */
    public static function timesInOtherZones(): array
    {
        return [
            'a London Monday evening' => ['2026-07-06 23:30', 'Europe/London', [['09:00', '17:00']]],
            'just after midnight on a London Tuesday, still Monday in UTC' => ['2026-07-07 00:30', 'Europe/London', [['10:00', '16:00']]],
            'an Auckland Tuesday morning, still Monday in UTC' => ['2026-07-07 09:00', 'Pacific/Auckland', [['10:00', '16:00']]],
        ];
    }

    /**
     * @param  list<array{string, string}>  $expectedHours
     */
    #[DataProvider('timesInOtherZones')]
    public function test_reads_the_calendar_date_in_the_time_zone_it_is_given_in(string $time, string $timezone, array $expectedHours): void
    {
        $pattern = $this->pattern('2026-07-06', null, '2026-07-06', [
            ['mon' => [['09:00', '17:00']], 'tue' => [['10:00', '16:00']]],
        ]);

        $hours = $pattern->hoursOn(CarbonImmutable::parse($time, $timezone))->toArray();

        $this->assertSame($expectedHours, $hours);
    }

    public function test_covers_reads_the_calendar_date_in_the_time_zone_it_is_given_in(): void
    {
        $pattern = $this->pattern('2026-07-07', '2026-07-13', '2026-07-06', [['tue' => [['09:00', '17:00']]]]);

        $this->assertTrue($pattern->covers(CarbonImmutable::parse('2026-07-07 09:00', 'Pacific/Auckland')));
        $this->assertFalse($pattern->covers(CarbonImmutable::parse('2026-07-14 00:30', 'Europe/London')));
    }

    public function test_hours_on_rejects_a_date_the_pattern_does_not_cover(): void
    {
        $pattern = $this->pattern('2026-11-02', '2026-11-13', '2026-11-02', [['mon' => [['09:00', '17:00']]]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('doesn\'t cover 2026-11-16');

        $pattern->hoursOn(CarbonImmutable::parse('2026-11-16'));
    }

    public function test_a_pattern_can_be_in_force_for_one_day(): void
    {
        $pattern = $this->pattern('2026-11-04', '2026-11-04', '2026-11-02', [['wed' => [['09:00', '17:00']]]]);

        $this->assertSame([['09:00', '17:00']], $this->hoursOn($pattern, '2026-11-04'));
    }

    public function test_rejects_a_pattern_that_ends_before_it_starts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('can\'t end before it starts');

        $this->pattern('2026-11-04', '2026-11-03', '2026-11-02', [[]]);
    }

    public function test_rejects_a_cycle_that_does_not_start_on_a_monday(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must start on a Monday, got 2026-11-03');

        $this->pattern('2026-11-04', null, '2026-11-03', [[]]);
    }

    public function test_rejects_a_cycle_that_starts_after_the_pattern(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('can\'t start after the pattern');

        $this->pattern('2026-11-04', null, '2026-11-09', [[]]);
    }

    /**
     * @param  list<array<string, list<array{string, string}>>>  $weeks  each week's working days; the rest are off
     */
    private function pattern(string $startsOn, ?string $endsOn, string $cycleStartsOn, array $weeks): RepeatingPattern
    {
        $offEveryDay = array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], []);

        return new RepeatingPattern(
            CarbonImmutable::parse($startsOn),
            $endsOn === null ? null : CarbonImmutable::parse($endsOn),
            CarbonImmutable::parse($cycleStartsOn),
            PatternWeeks::fromArray(array_map(fn (array $week): array => $week + $offEveryDay, $weeks)),
        );
    }

    /**
     * @return list<array{string, string}>
     */
    private function hoursOn(RepeatingPattern $pattern, string $date): array
    {
        return $pattern->hoursOn(CarbonImmutable::parse($date))->toArray();
    }
}
