<?php

namespace Tests\Feature\Database;

use App\Models\Business;
use App\Models\Practitioner;
use App\Models\TimeOff;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TimeOffTableTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function endsThatAreNotAfterTheStart(): array
    {
        return [
            'ending before it starts' => ['2026-11-10 13:00'],
            'ending as it starts' => ['2026-11-10 14:00'],
        ];
    }

    #[DataProvider('endsThatAreNotAfterTheStart')]
    public function test_rejects_time_off_that_does_not_end_after_it_starts(string $endsAt): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('"time_off_ends_at_check"');

        TimeOff::factory()->create([
            'starts_at' => CarbonImmutable::parse('2026-11-10 14:00'),
            'ends_at' => CarbonImmutable::parse($endsAt),
        ]);
    }

    public function test_allows_overlapping_time_off(): void
    {
        $practitioner = Practitioner::factory()->create();
        TimeOff::factory()->for($practitioner)->create([
            'starts_at' => CarbonImmutable::parse('2026-11-10 09:00'),
            'ends_at' => CarbonImmutable::parse('2026-11-10 17:00'),
        ]);

        $overlapping = TimeOff::factory()->for($practitioner)->create([
            'starts_at' => CarbonImmutable::parse('2026-11-10 12:00'),
            'ends_at' => CarbonImmutable::parse('2026-11-11 12:00'),
        ]);

        $this->assertModelExists($overlapping);
    }

    public function test_rejects_time_off_whose_business_differs_from_its_practitioners(): void
    {
        $practitioner = Practitioner::factory()->create();
        $otherBusiness = Business::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('"time_off_business_id_practitioner_id_foreign"');

        TimeOff::factory()->for($practitioner)->create(['business_id' => $otherBusiness->id]);
    }
}
