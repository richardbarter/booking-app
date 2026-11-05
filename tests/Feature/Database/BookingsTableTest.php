<?php

namespace Tests\Feature\Database;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Practitioner;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookingsTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_a_booking_whose_blocked_time_overlaps_another(): void
    {
        $practitioner = Practitioner::factory()->create();
        $this->bookAt($practitioner, '2026-11-10 10:00');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('"bookings_no_overlap"');

        $this->bookAt($practitioner, '2026-11-10 10:30');
    }

    public function test_rejects_a_booking_whose_buffer_overlaps_another_appointment(): void
    {
        $practitioner = Practitioner::factory()->create();
        $this->bookAt($practitioner, '2026-11-10 10:00');
        $withBufferBefore = Service::factory()->for($practitioner->business)->create(['buffer_before_minutes' => 15]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('"bookings_no_overlap"');

        $this->bookAt($practitioner, '2026-11-10 11:10', $withBufferBefore);
    }

    public function test_allows_blocked_times_that_only_touch(): void
    {
        $practitioner = Practitioner::factory()->create();
        $this->bookAt($practitioner, '2026-11-10 10:00');

        $next = $this->bookAt($practitioner, '2026-11-10 11:00');

        $this->assertModelExists($next);
    }

    public function test_a_cancelled_booking_does_not_block_its_time(): void
    {
        $practitioner = Practitioner::factory()->create();
        Booking::factory()->for($practitioner)->cancelled()->at(CarbonImmutable::parse('2026-11-10 10:00'))->create();

        $booking = $this->bookAt($practitioner, '2026-11-10 10:00');

        $this->assertModelExists($booking);
    }

    public function test_rejects_uncancelling_a_booking_into_an_overlap(): void
    {
        $practitioner = Practitioner::factory()->create();
        $cancelled = Booking::factory()->for($practitioner)->cancelled()->at(CarbonImmutable::parse('2026-11-10 10:00'))->create();
        $this->bookAt($practitioner, '2026-11-10 10:30');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('"bookings_no_overlap"');

        $cancelled->update(['status' => BookingStatus::Confirmed]);
    }

    public function test_a_no_show_still_blocks_its_time(): void
    {
        $practitioner = Practitioner::factory()->create();
        Booking::factory()->for($practitioner)->noShow()->at(CarbonImmutable::parse('2026-11-10 10:00'))->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('"bookings_no_overlap"');

        $this->bookAt($practitioner, '2026-11-10 10:00');
    }

    public function test_allows_another_businesss_booking_at_the_same_times(): void
    {
        $this->bookAt(Practitioner::factory()->create(), '2026-11-10 10:00');

        $other = $this->bookAt(Practitioner::factory()->create(), '2026-11-10 10:00');

        $this->assertModelExists($other);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function parents(): array
    {
        return [
            'practitioner' => ['practitioner'],
            'service' => ['service'],
            'customer' => ['customer'],
        ];
    }

    #[DataProvider('parents')]
    public function test_rejects_a_booking_whose_parent_belongs_to_another_business(string $parent): void
    {
        $booking = Booking::factory()->make();
        $otherBusiness = Business::factory()->create();
        $other = match ($parent) {
            'practitioner' => Practitioner::factory()->for($otherBusiness)->create(),
            'service' => Service::factory()->for($otherBusiness)->create(),
            default => Customer::factory()->for($otherBusiness)->create(),
        };

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("\"bookings_business_id_{$parent}_id_foreign\"");

        $booking->forceFill(["{$parent}_id" => $other->id])->save();
    }

    #[DataProvider('parents')]
    public function test_rejects_deleting_a_parent_that_has_bookings(string $parent): void
    {
        $practitioner = Practitioner::factory()->create();
        $parents = [
            'practitioner' => $practitioner,
            'service' => Service::factory()->for($practitioner->business)->create(),
            'customer' => Customer::factory()->for($practitioner->business)->create(),
        ];
        Booking::factory()->for($parents['practitioner'])->for($parents['service'])->for($parents['customer'])->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("\"bookings_business_id_{$parent}_id_foreign\"");

        $parents[$parent]->delete();
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function invalidTimes(): array
    {
        return [
            'ending as it starts' => [['ends_at' => '2026-11-10 10:00'], 'bookings_ends_at_check'],
            'ending before it starts' => [['ends_at' => '2026-11-10 09:00'], 'bookings_ends_at_check'],
            'blocked from after the start' => [['blocked_from' => '2026-11-10 10:05'], 'bookings_blocked_time_check'],
            'blocked until before the end' => [['blocked_until' => '2026-11-10 10:55'], 'bookings_blocked_time_check'],
        ];
    }

    /**
     * @param  array<string, string>  $times
     */
    #[DataProvider('invalidTimes')]
    public function test_rejects_times_that_do_not_hold_together(array $times, string $constraint): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("\"{$constraint}\"");

        Booking::factory()
            ->at(CarbonImmutable::parse('2026-11-10 10:00'))
            ->create(array_map(fn (string $time): CarbonImmutable => CarbonImmutable::parse($time), $times));
    }

    public function test_rejects_a_negative_price(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('"bookings_price_pence_check"');

        Booking::factory()->create(['price_pence' => -1]);
    }

    /**
     * Written with the query builder, since the model's enum casts refuse
     * these before they reach the database.
     *
     * @return array<string, array{string, string}>
     */
    public static function unknownValues(): array
    {
        return [
            'status' => ['status', 'bookings_status_check'],
            'source' => ['source', 'bookings_source_check'],
        ];
    }

    #[DataProvider('unknownValues')]
    public function test_rejects_an_unknown_status_or_source(string $column, string $constraint): void
    {
        $attributes = Booking::factory()->make()->getAttributes();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("\"{$constraint}\"");

        DB::table('bookings')->insert([...$attributes, $column => 'completed']);
    }

    private function bookAt(Practitioner $practitioner, string $startsAt, ?Service $service = null): Booking
    {
        return Booking::factory()
            ->for($practitioner)
            ->for($service ?? Service::factory()->for($practitioner->business)->create())
            ->at(CarbonImmutable::parse($startsAt))
            ->create();
    }
}
