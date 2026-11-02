<?php

namespace Tests\Feature\Casts;

use App\Models\EditedDay;
use App\Models\Practitioner;
use App\Models\Scopes\BusinessScope;
use App\Scheduling\Hours\DayHours;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class AsDayHoursTest extends TestCase
{
    use RefreshDatabase;

    public function test_hours_round_trip_through_the_database(): void
    {
        $editedDay = EditedDay::factory()->create(['hours' => [['14:00', '17:30'], ['09:00', '13:00']]]);

        $hours = $this->reload($editedDay->id)->hours;

        $this->assertInstanceOf(DayHours::class, $hours);
        $this->assertSame([['09:00', '13:00'], ['14:00', '17:30']], $hours->toArray());
    }

    public function test_a_day_off_round_trips_as_no_hours(): void
    {
        $editedDay = EditedDay::factory()->dayOff()->create();

        $this->assertTrue($this->reload($editedDay->id)->hours->isEmpty());
    }

    public function test_invalid_hours_in_the_database_throw_when_read(): void
    {
        $practitioner = Practitioner::factory()->create();
        $id = DB::table('edited_days')->insertGetId([
            'business_id' => $practitioner->business_id,
            'practitioner_id' => $practitioner->id,
            'date' => '2026-11-11',
            'hours' => '[["13:00", "09:00"]]',
        ]);
        $editedDay = $this->reload($id);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must start before it ends');

        $editedDay->getAttribute('hours');
    }

    public function test_invalid_hours_in_the_database_can_be_replaced(): void
    {
        $practitioner = Practitioner::factory()->create();
        $id = DB::table('edited_days')->insertGetId([
            'business_id' => $practitioner->business_id,
            'practitioner_id' => $practitioner->id,
            'date' => '2026-11-11',
            'hours' => '[["09:03", "10:00"]]',
        ]);
        $editedDay = $this->reload($id);

        $editedDay->hours = DayHours::fromArray([['09:00', '10:00']]);
        $editedDay->save();

        $this->assertSame([['09:00', '10:00']], $this->reload($id)->hours->toArray());
    }

    public function test_to_array_gives_the_hours_in_their_stored_form(): void
    {
        $editedDay = new EditedDay(['hours' => [['09:00', '13:00'], ['14:00', '17:30']]]);

        $this->assertSame([['09:00', '13:00'], ['14:00', '17:30']], $editedDay->toArray()['hours']);
    }

    public function test_setting_invalid_hours_throws_before_saving(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('can\'t overlap or touch');

        new EditedDay(['hours' => [['09:00', '13:00'], ['12:00', '17:00']]]);
    }

    public function test_reading_the_hours_does_not_mark_them_changed(): void
    {
        $editedDay = $this->reload(EditedDay::factory()->create()->id);

        $editedDay->getAttribute('hours');

        $this->assertFalse($editedDay->isDirty());
    }

    public function test_the_same_hours_in_another_order_are_not_a_change(): void
    {
        $editedDay = $this->reload(EditedDay::factory()->create(['hours' => [['09:00', '13:00'], ['14:00', '17:30']]])->id);

        $editedDay->hours = DayHours::fromArray([['14:00', '17:30'], ['09:00', '13:00']]);

        $this->assertFalse($editedDay->isDirty('hours'));
    }

    public function test_different_hours_are_a_change(): void
    {
        $editedDay = $this->reload(EditedDay::factory()->create(['hours' => [['09:00', '13:00']]])->id);

        $editedDay->hours = DayHours::fromArray([['09:00', '12:00']]);

        $this->assertTrue($editedDay->isDirty('hours'));
    }

    private function reload(int $id): EditedDay
    {
        return EditedDay::withoutGlobalScope(BusinessScope::class)->findOrFail($id);
    }
}
