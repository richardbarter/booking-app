<?php

namespace Tests\Feature\Database;

use App\Models\Booking;
use App\Models\EditedDay;
use App\Models\Practitioner;
use App\Models\Service;
use App\Models\TimeOff;
use App\Models\WorkingPattern;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Deleting a business is how expired playground sandboxes go (08), so it
 * must take everything the business owns with it, and nothing else.
 */
class DeletingABusinessTest extends TestCase
{
    use RefreshDatabase;

    private const array TENANT_TABLES = ['practitioners', 'services', 'working_patterns', 'edited_days', 'time_off', 'customers', 'bookings'];

    public function test_deletes_all_its_data_and_leaves_another_businesss_alone(): void
    {
        $doomed = $this->practitionerWithOneOfEverything();
        $kept = $this->practitionerWithOneOfEverything();

        $doomed->business->delete();

        $this->assertModelMissing($doomed->business);
        foreach (self::TENANT_TABLES as $table) {
            $this->assertDatabaseMissing($table, ['business_id' => $doomed->business_id]);
            $this->assertSame(1, DB::table($table)->where('business_id', $kept->business_id)->count(), "{$table} lost the other business's row.");
        }
    }

    private function practitionerWithOneOfEverything(): Practitioner
    {
        $practitioner = Practitioner::factory()->create();
        $service = Service::factory()->for($practitioner->business)->create();
        WorkingPattern::factory()->for($practitioner)->create();
        EditedDay::factory()->for($practitioner)->create();
        TimeOff::factory()->for($practitioner)->create();
        Booking::factory()->for($practitioner)->for($service)->create();

        return $practitioner;
    }
}
