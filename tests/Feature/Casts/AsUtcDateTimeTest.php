<?php

namespace Tests\Feature\Casts;

use App\Models\Scopes\BusinessScope;
use App\Models\TimeOff;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use TypeError;

class AsUtcDateTimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_stores_10_00_london_time_in_july_as_09_00_utc(): void
    {
        $timeOff = $this->timeOffFromTenInLondon();

        $this->assertSame('2026-07-01 09:00:00+00', DB::table('time_off')->where('id', $timeOff->id)->value('starts_at'));
    }

    public function test_reads_the_instant_back_in_utc(): void
    {
        $timeOff = $this->timeOffFromTenInLondon();

        $startsAt = $this->reload($timeOff)->starts_at;

        $this->assertInstanceOf(CarbonImmutable::class, $startsAt);
        $this->assertSame('2026-07-01T09:00:00+00:00', $startsAt->toIso8601String());
        $this->assertSame('UTC', $startsAt->getTimezone()->getName());
    }

    public function test_setting_a_string_throws_because_it_has_no_time_zone(): void
    {
        $this->expectException(TypeError::class);

        new TimeOff(['starts_at' => '2026-07-01 10:00']);
    }

    public function test_reading_the_instant_does_not_mark_it_changed(): void
    {
        $timeOff = $this->reload($this->timeOffFromTenInLondon());

        $timeOff->getAttribute('starts_at');

        $this->assertFalse($timeOff->isDirty());
    }

    public function test_the_same_instant_in_another_zone_is_not_a_change(): void
    {
        $timeOff = $this->reload($this->timeOffFromTenInLondon());

        $timeOff->starts_at = CarbonImmutable::parse('2026-07-01 21:00', 'Pacific/Auckland');

        $this->assertFalse($timeOff->isDirty('starts_at'));
    }

    public function test_a_different_instant_is_a_change(): void
    {
        $timeOff = $this->reload($this->timeOffFromTenInLondon());

        $timeOff->starts_at = CarbonImmutable::parse('2026-07-01 10:00', 'UTC');

        $this->assertTrue($timeOff->isDirty('starts_at'));
    }

    private function timeOffFromTenInLondon(): TimeOff
    {
        return TimeOff::factory()->create([
            'starts_at' => CarbonImmutable::parse('2026-07-01 10:00', 'Europe/London'),
            'ends_at' => CarbonImmutable::parse('2026-07-01 12:00', 'Europe/London'),
        ]);
    }

    private function reload(TimeOff $timeOff): TimeOff
    {
        return TimeOff::withoutGlobalScope(BusinessScope::class)->findOrFail($timeOff->id);
    }
}
