<?php

namespace Tests\Unit\Scheduling;

use App\Scheduling\FreeGaps;
use App\Scheduling\TimeRange;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Times are on Monday 9 November 2026, in UTC, unless they give a date.
 */
class FreeGapsTest extends TestCase
{
    public function test_with_no_blocked_time_the_working_times_are_the_free_gaps(): void
    {
        $gaps = FreeGaps::between([$this->range('09:00', '13:00'), $this->range('14:00', '17:30')], []);

        $this->assertSame([['09:00', '13:00'], ['14:00', '17:30']], $this->times($gaps));
    }

    public function test_blocked_time_in_the_middle_splits_a_working_time_in_two(): void
    {
        $gaps = FreeGaps::between([$this->range('09:00', '12:00')], [$this->range('10:00', '10:30')]);

        $this->assertSame([['09:00', '10:00'], ['10:30', '12:00']], $this->times($gaps));
    }

    public function test_blocked_time_at_the_start_trims_a_working_time(): void
    {
        $gaps = FreeGaps::between([$this->range('09:00', '12:00')], [$this->range('09:00', '10:00')]);

        $this->assertSame([['10:00', '12:00']], $this->times($gaps));
    }

    public function test_blocked_time_at_the_end_trims_a_working_time(): void
    {
        $gaps = FreeGaps::between([$this->range('09:00', '12:00')], [$this->range('11:00', '12:00')]);

        $this->assertSame([['09:00', '11:00']], $this->times($gaps));
    }

    public function test_blocked_time_running_past_either_end_trims_a_working_time(): void
    {
        $gaps = FreeGaps::between([$this->range('09:00', '12:00')], [$this->range('08:00', '09:30'), $this->range('11:30', '13:00')]);

        $this->assertSame([['09:30', '11:30']], $this->times($gaps));
    }

    public function test_blocked_time_covering_a_working_time_removes_it(): void
    {
        $gaps = FreeGaps::between([$this->range('09:00', '12:00'), $this->range('14:00', '17:00')], [$this->range('08:00', '13:00')]);

        $this->assertSame([['14:00', '17:00']], $this->times($gaps));
    }

    public function test_blocked_time_outside_the_working_times_changes_nothing(): void
    {
        $gaps = FreeGaps::between([$this->range('09:00', '12:00')], [$this->range('07:00', '09:00'), $this->range('12:00', '13:00')]);

        $this->assertSame([['09:00', '12:00']], $this->times($gaps));
    }

    public function test_overlapping_and_touching_blocked_times_act_as_one(): void
    {
        $gaps = FreeGaps::between([$this->range('09:00', '17:00')], [
            $this->range('10:00', '11:30'),
            $this->range('10:30', '11:00'),
            $this->range('11:30', '12:00'),
            $this->range('11:45', '13:00'),
        ]);

        $this->assertSame([['09:00', '10:00'], ['13:00', '17:00']], $this->times($gaps));
    }

    public function test_blocked_times_can_be_given_in_any_order(): void
    {
        $gaps = FreeGaps::between([$this->range('09:00', '17:00')], [$this->range('15:00', '16:00'), $this->range('10:00', '11:00')]);

        $this->assertSame([['09:00', '10:00'], ['11:00', '15:00'], ['16:00', '17:00']], $this->times($gaps));
    }

    public function test_blocked_time_across_midnight_affects_both_days(): void
    {
        $gaps = FreeGaps::between(
            [$this->between('2026-11-09 18:00', '2026-11-10 00:00'), $this->between('2026-11-10 00:00', '2026-11-10 06:00')],
            [$this->between('2026-11-09 22:00', '2026-11-10 02:00')],
        );

        $this->assertSame([
            ['2026-11-09 18:00', '2026-11-09 22:00'],
            ['2026-11-10 02:00', '2026-11-10 06:00'],
        ], $this->times($gaps, 'Y-m-d H:i'));
    }

    public function test_two_working_times_that_meet_at_midnight_stay_two_gaps(): void
    {
        $gaps = FreeGaps::between(
            [$this->between('2026-11-09 18:00', '2026-11-10 00:00'), $this->between('2026-11-10 00:00', '2026-11-10 06:00')],
            [],
        );

        $this->assertCount(2, $gaps);
    }

    private function range(string $start, string $end): TimeRange
    {
        return $this->between("2026-11-09 {$start}", "2026-11-09 {$end}");
    }

    private function between(string $start, string $end): TimeRange
    {
        return new TimeRange(CarbonImmutable::parse($start, 'UTC'), CarbonImmutable::parse($end, 'UTC'));
    }

    /**
     * @param  list<TimeRange>  $ranges
     * @return list<array{string, string}>
     */
    private function times(array $ranges, string $format = 'H:i'): array
    {
        return array_map(fn (TimeRange $range): array => [$range->start->format($format), $range->end->format($format)], $ranges);
    }
}
