<?php

namespace Tests\Unit\Scheduling;

use App\Scheduling\GapSummary;
use App\Scheduling\TimeRange;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class GapSummaryTest extends TestCase
{
    public function test_counts_the_whole_minutes_in_each_list_of_gaps(): void
    {
        $summary = new GapSummary(
            [$this->range('09:00', '09:20'), $this->range('12:40', '13:00')],
            [$this->range('10:00', '11:00')],
        );

        $this->assertSame(40, $summary->strandedMinutes());
        $this->assertSame(60, $summary->fragmentedMinutes());
    }

    public function test_never_counts_a_gap_as_longer_than_it_is(): void
    {
        $summary = new GapSummary([$this->range('10:00:00.5', '10:15')]);

        $this->assertSame(14, $summary->strandedMinutes());
    }

    public function test_has_no_minutes_without_gaps(): void
    {
        $summary = new GapSummary;

        $this->assertSame([0, 0], [$summary->strandedMinutes(), $summary->fragmentedMinutes()]);
    }

    private function range(string $start, string $end): TimeRange
    {
        return new TimeRange(
            CarbonImmutable::parse("2026-11-09 {$start}", 'UTC'),
            CarbonImmutable::parse("2026-11-09 {$end}", 'UTC'),
        );
    }
}
