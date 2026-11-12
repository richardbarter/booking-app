<?php

namespace Tests\Unit\Scheduling;

use App\Scheduling\TimeRange;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TimeRangeTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function endsNotAfterTheStart(): array
    {
        return [
            'the same instant' => ['2026-11-09T10:00:00Z', '2026-11-09T10:00:00Z'],
            'an end before the start' => ['2026-11-09T10:00:00Z', '2026-11-09T09:59:00Z'],
            'the same instant in two zones' => ['2026-07-06T10:00:00+01:00', '2026-07-06T09:00:00Z'],
        ];
    }

    #[DataProvider('endsNotAfterTheStart')]
    public function test_rejects_an_end_at_or_before_its_start(string $start, string $end): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must start before it ends');

        new TimeRange(CarbonImmutable::parse($start), CarbonImmutable::parse($end));
    }

    public function test_stores_its_instants_in_utc_whatever_zone_they_were_given_in(): void
    {
        $range = new TimeRange(
            CarbonImmutable::parse('2026-07-06 10:00', 'Europe/London'),
            CarbonImmutable::parse('2026-07-07 01:00', 'Pacific/Auckland'),
        );

        $this->assertSame('2026-07-06T09:00:00+00:00', $range->start->toIso8601String());
        $this->assertSame('2026-07-06T13:00:00+00:00', $range->end->toIso8601String());
        $this->assertSame('UTC', $range->start->timezoneName);
        $this->assertSame('UTC', $range->end->timezoneName);
    }

    public function test_does_not_change_or_keep_a_mutable_date_it_is_given(): void
    {
        $start = Carbon::parse('2026-07-06 10:00', 'Europe/London');

        $range = new TimeRange($start, $start->copy()->addHour());
        $start->addDay();

        $this->assertSame('Europe/London', $start->timezoneName);
        $this->assertSame('2026-07-06T09:00:00+00:00', $range->start->toIso8601String());
    }

    public function test_ranges_that_only_touch_do_not_overlap(): void
    {
        $morning = $this->range('09:00', '10:00');
        $next = $this->range('10:00', '11:00');

        $this->assertFalse($morning->overlaps($next));
        $this->assertFalse($next->overlaps($morning));
    }

    public function test_ranges_sharing_a_minute_overlap(): void
    {
        $morning = $this->range('09:00', '10:01');
        $next = $this->range('10:00', '11:00');

        $this->assertTrue($morning->overlaps($next));
        $this->assertTrue($next->overlaps($morning));
    }

    public function test_a_range_overlaps_one_inside_it(): void
    {
        $this->assertTrue($this->range('09:00', '12:00')->overlaps($this->range('10:00', '11:00')));
    }

    public function test_ranges_apart_do_not_overlap(): void
    {
        $this->assertFalse($this->range('09:00', '10:00')->overlaps($this->range('11:00', '12:00')));
        $this->assertFalse($this->range('11:00', '12:00')->overlaps($this->range('09:00', '10:00')));
    }

    public function test_contains_a_range_with_the_same_start_and_end(): void
    {
        $this->assertTrue($this->range('09:00', '12:00')->contains($this->range('09:00', '12:00')));
    }

    public function test_contains_a_range_inside_it(): void
    {
        $this->assertTrue($this->range('09:00', '12:00')->contains($this->range('10:00', '11:00')));
    }

    public function test_does_not_contain_a_range_that_runs_past_either_end(): void
    {
        $gap = $this->range('09:00', '12:00');

        $this->assertFalse($gap->contains($this->range('08:59', '10:00')));
        $this->assertFalse($gap->contains($this->range('11:00', '12:01')));
    }

    public function test_whole_seconds_inside_round_a_part_second_start_up_and_end_down(): void
    {
        $range = new TimeRange(
            CarbonImmutable::parse('2026-11-09 09:00:00.25', 'UTC'),
            CarbonImmutable::parse('2026-11-09 10:00:00.75', 'UTC'),
        );

        $this->assertSame([
            CarbonImmutable::parse('2026-11-09 09:00:01', 'UTC')->getTimestamp(),
            CarbonImmutable::parse('2026-11-09 10:00:00', 'UTC')->getTimestamp(),
        ], $range->wholeSecondsInside());
    }

    public function test_whole_seconds_inside_keep_whole_seconds_as_they_are(): void
    {
        $range = $this->range('09:00', '10:00');

        $this->assertSame([$range->start->getTimestamp(), $range->end->getTimestamp()], $range->wholeSecondsInside());
    }

    private function range(string $start, string $end): TimeRange
    {
        return new TimeRange(
            CarbonImmutable::parse("2026-11-09 {$start}", 'UTC'),
            CarbonImmutable::parse("2026-11-09 {$end}", 'UTC'),
        );
    }
}
