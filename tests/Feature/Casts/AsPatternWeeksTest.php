<?php

namespace Tests\Feature\Casts;

use App\Models\Practitioner;
use App\Models\Scopes\BusinessScope;
use App\Models\WorkingPattern;
use App\Scheduling\Hours\PatternWeeks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class AsPatternWeeksTest extends TestCase
{
    use RefreshDatabase;

    public function test_weeks_round_trip_through_the_database(): void
    {
        $pattern = WorkingPattern::factory()->fortnightly()->create();

        $weeks = $this->reload($pattern->id)->weeks;

        $this->assertInstanceOf(PatternWeeks::class, $weeks);
        $this->assertCount(2, $weeks);
        $this->assertSame([['09:00', '17:00']], $weeks->week(0)->forWeekday(5)->toArray());
        $this->assertSame([], $weeks->week(1)->forWeekday(5)->toArray());
    }

    public function test_invalid_weeks_in_the_database_throw_when_read(): void
    {
        $practitioner = Practitioner::factory()->create();
        $id = DB::table('working_patterns')->insertGetId([
            'business_id' => $practitioner->business_id,
            'practitioner_id' => $practitioner->id,
            'starts_on' => '2026-11-02',
            'cycle_starts_on' => '2026-11-02',
            'weeks' => '[{"mon": [["09:00", "17:00"]]}]',
        ]);
        $pattern = $this->reload($id);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exactly the keys');

        $pattern->getAttribute('weeks');
    }

    public function test_setting_invalid_weeks_throws_before_saving(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must have 1 to 4 weeks');

        new WorkingPattern(['weeks' => []]);
    }

    public function test_reading_the_weeks_does_not_mark_them_changed(): void
    {
        $pattern = $this->reload(WorkingPattern::factory()->create()->id);

        $pattern->getAttribute('weeks');

        $this->assertFalse($pattern->isDirty());
    }

    public function test_the_same_weeks_set_again_are_not_a_change(): void
    {
        $pattern = $this->reload(WorkingPattern::factory()->create()->id);

        $pattern->weeks = PatternWeeks::fromArray($pattern->weeks->toArray());

        $this->assertFalse($pattern->isDirty('weeks'));
    }

    public function test_different_weeks_are_a_change(): void
    {
        $pattern = $this->reload(WorkingPattern::factory()->create()->id);

        $pattern->weeks = $this->reload(WorkingPattern::factory()->fortnightly()->create()->id)->weeks;

        $this->assertTrue($pattern->isDirty('weeks'));
    }

    private function reload(int $id): WorkingPattern
    {
        return WorkingPattern::withoutGlobalScope(BusinessScope::class)->findOrFail($id);
    }
}
