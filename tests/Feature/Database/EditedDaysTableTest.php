<?php

namespace Tests\Feature\Database;

use App\Models\Business;
use App\Models\EditedDay;
use App\Models\Practitioner;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EditedDaysTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_a_second_edited_day_for_the_same_practitioner_and_date(): void
    {
        $practitioner = Practitioner::factory()->create();
        EditedDay::factory()->for($practitioner)->create(['date' => '2026-11-11']);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('"edited_days_practitioner_id_date_unique"');

        EditedDay::factory()->for($practitioner)->dayOff()->create(['date' => '2026-11-11']);
    }

    public function test_allows_the_same_date_for_another_practitioner(): void
    {
        EditedDay::factory()->create(['date' => '2026-11-11']);

        $other = EditedDay::factory()->create(['date' => '2026-11-11']);

        $this->assertModelExists($other);
    }

    public function test_rejects_hours_that_are_not_an_array(): void
    {
        $practitioner = Practitioner::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('"edited_days_hours_check"');

        DB::table('edited_days')->insert([
            'business_id' => $practitioner->business_id,
            'practitioner_id' => $practitioner->id,
            'date' => '2026-11-11',
            'hours' => '{"from": "10:00", "until": "16:00"}',
        ]);
    }

    public function test_rejects_an_edited_day_whose_business_differs_from_its_practitioners(): void
    {
        $practitioner = Practitioner::factory()->create();
        $otherBusiness = Business::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('"edited_days_business_id_practitioner_id_foreign"');

        EditedDay::factory()->for($practitioner)->create(['business_id' => $otherBusiness->id]);
    }
}
