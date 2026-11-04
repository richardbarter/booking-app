<?php

namespace Tests\Unit\Scheduling;

use App\Scheduling\DateRange;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DateRangeTest extends TestCase
{
    public function test_lists_each_date_from_first_to_last(): void
    {
        $range = DateRange::between(CarbonImmutable::parse('2026-11-09'), CarbonImmutable::parse('2026-11-12'));

        $this->assertSame('2026-11-09', $range->first);
        $this->assertSame('2026-11-12', $range->last);
        $this->assertSame(['2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12'], $range->dates());
    }

    public function test_a_range_can_be_one_date(): void
    {
        $range = DateRange::between(CarbonImmutable::parse('2026-11-09'), CarbonImmutable::parse('2026-11-09'));

        $this->assertSame(['2026-11-09'], $range->dates());
    }

    public function test_lists_each_date_once_across_the_clock_changes(): void
    {
        $spring = DateRange::between(
            CarbonImmutable::parse('2027-03-27', 'Europe/London'),
            CarbonImmutable::parse('2027-03-29', 'Europe/London'),
        );
        $autumn = DateRange::between(
            CarbonImmutable::parse('2027-10-30', 'Europe/London'),
            CarbonImmutable::parse('2027-11-01', 'Europe/London'),
        );

        $this->assertSame(['2027-03-27', '2027-03-28', '2027-03-29'], $spring->dates());
        $this->assertSame(['2027-10-30', '2027-10-31', '2027-11-01'], $autumn->dates());
    }

    public function test_reads_the_calendar_date_in_the_zone_each_date_is_given_in(): void
    {
        $range = DateRange::between(
            CarbonImmutable::parse('2026-11-10 08:00', 'Pacific/Auckland'),
            CarbonImmutable::parse('2026-11-10 23:30', 'America/New_York'),
        );

        $this->assertSame(['2026-11-10'], $range->dates());
    }

    public function test_rejects_a_last_date_before_the_first(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('can\'t end before it starts, got 2026-11-10 to 2026-11-09');

        DateRange::between(CarbonImmutable::parse('2026-11-10'), CarbonImmutable::parse('2026-11-09'));
    }

    public function test_allows_366_dates(): void
    {
        $range = DateRange::between(CarbonImmutable::parse('2027-01-01'), CarbonImmutable::parse('2028-01-01'));

        $this->assertCount(366, $range->dates());
    }

    public function test_rejects_367_dates(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at most 366 dates, got 367');

        DateRange::between(CarbonImmutable::parse('2027-01-01'), CarbonImmutable::parse('2028-01-02'));
    }
}
