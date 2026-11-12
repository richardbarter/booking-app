<?php

namespace Tests\Unit\Scheduling;

use App\Scheduling\Availability;
use App\Scheduling\DayAvailability;
use App\Scheduling\GapSummary;
use PHPUnit\Framework\TestCase;

class AvailabilityTest extends TestCase
{
    public function test_finds_the_day_for_a_date(): void
    {
        $monday = new DayAvailability('2026-11-09', [], [], [], new GapSummary);
        $tuesday = new DayAvailability('2026-11-10', [], [], [], new GapSummary);

        $availability = new Availability([$monday, $tuesday]);

        $this->assertSame($tuesday, $availability->day('2026-11-10'));
        $this->assertSame($monday, $availability->day('2026-11-09'));
    }

    public function test_has_no_day_for_a_date_outside_its_range(): void
    {
        $availability = new Availability([new DayAvailability('2026-11-09', [], [], [], new GapSummary)]);

        $this->assertNull($availability->day('2026-11-10'));
    }
}
