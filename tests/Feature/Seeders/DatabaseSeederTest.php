<?php

namespace Tests\Feature\Seeders;

use App\Models\Business;
use App\Models\Service;
use App\Services\CurrentBusiness;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_the_test_user_and_the_sample_business(): void
    {
        $this->seed();

        $this->assertDatabaseHas('users', ['email' => 'test@example.com']);
        $this->assertDatabaseHas('businesses', ['name' => 'Alex Morgan Physio & Sports Massage']);
        $this->assertDatabaseCount('practitioners', 1);
        $this->assertDatabaseCount('services', 3);
    }

    public function test_seeds_three_bookings_next_monday_that_the_offered_times_leave_out(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00', 'Europe/London'));

        $this->seed();

        $business = Business::query()->sole();
        $this->assertDatabaseCount('bookings', 3);
        $this->assertDatabaseCount('customers', 3);
        $this->artisan('availability:show', ['business' => $business->id, 'service' => $this->assessmentId($business), '--date' => '2026-11-09'])
            ->expectsOutput('Free gaps      09:00–09:30, 10:30–11:00, 11:30–13:00, 14:00–14:30, 16:00–17:30')
            ->expectsOutput('  11:30 11:45 12:00 16:00 16:15 16:30')
            ->assertSuccessful();
    }

    private function assessmentId(Business $business): int
    {
        return app(CurrentBusiness::class)->run($business, fn (): int => Service::query()->where('name', 'Physio assessment')->sole()->id);
    }
}
