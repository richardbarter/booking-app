<?php

namespace Tests\Feature\Database;

use App\Models\Business;
use App\Models\Practitioner;
use App\Models\WorkingPattern;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkingPatternsTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_a_pattern_that_overlaps_another_by_one_day(): void
    {
        $practitioner = Practitioner::factory()->create();
        $this->pattern($practitioner, '2026-11-02', '2026-11-15');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('"working_patterns_no_overlap"');

        $this->pattern($practitioner, '2026-11-15', null);
    }

    public function test_rejects_a_pattern_that_overlaps_an_open_ended_one(): void
    {
        $practitioner = Practitioner::factory()->create();
        $this->pattern($practitioner, '2026-11-02', null);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('"working_patterns_no_overlap"');

        $this->pattern($practitioner, '2027-06-07', '2027-08-29');
    }

    public function test_allows_a_pattern_starting_the_day_after_another_ends(): void
    {
        $practitioner = Practitioner::factory()->create();
        $this->pattern($practitioner, '2026-11-02', '2026-11-15');

        $next = $this->pattern($practitioner, '2026-11-16', null);

        $this->assertModelExists($next);
    }

    public function test_allows_the_same_dates_for_another_practitioner(): void
    {
        $this->pattern(Practitioner::factory()->create(), '2026-11-02', null);

        $other = $this->pattern(Practitioner::factory()->create(), '2026-11-02', null);

        $this->assertModelExists($other);
    }

    /**
     * @return array<string, array{string, ?string, string, string}>
     */
    public static function invalidDates(): array
    {
        return [
            'a cycle starting on a Tuesday' => ['2026-11-04', null, '2026-11-03', 'working_patterns_cycle_starts_on_monday_check'],
            'a cycle starting after the pattern' => ['2026-11-04', null, '2026-11-09', 'working_patterns_cycle_starts_on_check'],
            'an end before the start' => ['2026-11-04', '2026-11-03', '2026-11-02', 'working_patterns_ends_on_check'],
        ];
    }

    #[DataProvider('invalidDates')]
    public function test_rejects_invalid_dates(string $startsOn, ?string $endsOn, string $cycleStartsOn, string $constraint): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("\"{$constraint}\"");

        WorkingPattern::factory()->create([
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'cycle_starts_on' => $cycleStartsOn,
        ]);
    }

    public function test_allows_a_pattern_in_force_for_one_day(): void
    {
        $pattern = $this->pattern(Practitioner::factory()->create(), '2026-11-04', '2026-11-04');

        $this->assertModelExists($pattern);
    }

    /**
     * Written with the query builder, since the model's cast refuses these
     * before they reach the database.
     *
     * @return array<string, array{string}>
     */
    public static function invalidWeeks(): array
    {
        return [
            'no weeks' => ['[]'],
            '5 weeks' => ['[{}, {}, {}, {}, {}]'],
            'an object' => ['{"mon": []}'],
        ];
    }

    #[DataProvider('invalidWeeks')]
    public function test_rejects_weeks_that_are_not_1_to_4_items(string $weeks): void
    {
        $practitioner = Practitioner::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('"working_patterns_weeks_check"');

        DB::table('working_patterns')->insert([
            'business_id' => $practitioner->business_id,
            'practitioner_id' => $practitioner->id,
            'starts_on' => '2026-11-02',
            'cycle_starts_on' => '2026-11-02',
            'weeks' => $weeks,
        ]);
    }

    public function test_rejects_a_pattern_whose_business_differs_from_its_practitioners(): void
    {
        $practitioner = Practitioner::factory()->create();
        $otherBusiness = Business::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('"working_patterns_business_id_practitioner_id_foreign"');

        WorkingPattern::factory()->for($practitioner)->create(['business_id' => $otherBusiness->id]);
    }

    private function pattern(Practitioner $practitioner, string $startsOn, ?string $endsOn): WorkingPattern
    {
        $factory = WorkingPattern::factory()->for($practitioner)->startingOn(CarbonImmutable::parse($startsOn));

        return ($endsOn === null ? $factory : $factory->endingOn(CarbonImmutable::parse($endsOn)))->create();
    }
}
