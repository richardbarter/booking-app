<?php

namespace Tests\Feature\Models;

use App\Models\WorkingPattern;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkingPatternTest extends TestCase
{
    use RefreshDatabase;

    public function test_to_repeating_pattern_gives_the_patterns_dates_and_hours(): void
    {
        $pattern = WorkingPattern::factory()
            ->fortnightly()
            ->startingOn(CarbonImmutable::parse('2026-11-04'))
            ->endingOn(CarbonImmutable::parse('2027-03-31'))
            ->create();

        $repeating = $pattern->toRepeatingPattern();

        $this->assertSame('2026-11-04', $repeating->startsOn);
        $this->assertSame('2027-03-31', $repeating->endsOn);
        $this->assertSame('2026-11-02', $repeating->cycleStartsOn);
        $this->assertSame($pattern->weeks->toArray(), $repeating->weeks->toArray());
        $this->assertCount(2, $repeating->weeks);
    }
}
