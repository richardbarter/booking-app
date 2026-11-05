<?php

namespace Tests\Feature\Models;

use App\Models\Booking;
use App\Models\Practitioner;
use App\Models\Service;
use App\Services\CurrentBusiness;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use RefreshDatabase;

    public function test_attended_keeps_confirmed_bookings_that_have_ended_and_leaves_out_the_rest(): void
    {
        $practitioner = Practitioner::factory()->create();
        $service = Service::factory()->for($practitioner->business)->create(['duration_minutes' => 60]);
        $book = fn (string $startsAt) => Booking::factory()->for($practitioner)->for($service)->at(CarbonImmutable::parse($startsAt, 'UTC'));
        $ended = $book('2026-11-09 08:00')->create();
        $endingNow = $book('2026-11-09 10:00')->create();
        $book('2026-11-09 12:00')->create();
        $book('2026-11-09 07:00')->cancelled()->create();
        $book('2026-11-09 06:00')->noShow()->create();

        $attended = app(CurrentBusiness::class)->run(
            $practitioner->business,
            fn (): array => Booking::attended(CarbonImmutable::parse('2026-11-09 11:00', 'UTC'))->orderBy('id')->pluck('id')->all(),
        );

        $this->assertSame([$ended->id, $endingNow->id], $attended);
    }
}
