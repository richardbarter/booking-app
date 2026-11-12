<?php

namespace Tests\Feature\Console\Commands;

use App\Actions\CreateSampleBusiness;
use App\Enums\BookingSource;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Scopes\BusinessScope;
use App\Models\Service;
use App\Services\CurrentBusiness;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The sample business, created on Monday 2 November 2026 at 08:00 in
 * London, as in AvailabilityServiceTest.
 */
class BookSlotTest extends TestCase
{
    use RefreshDatabase;

    public function test_books_an_offered_time_for_the_default_customer(): void
    {
        $business = $this->sampleBusiness();
        $assessment = $this->service($business, 'Physio assessment');

        $this->artisan('booking:create', ['business' => $business->id, 'service' => $assessment->id, 'start' => '2026-11-09 10:00'])
            ->expectsOutputToContain('Booked Physio assessment for Monday 9 November 2026, 10:00–11:00 (Europe/London), booking ')
            ->doesntExpectOutputToContain('Test Customer')
            ->assertSuccessful();

        $booking = $this->bookingOf($business);
        $this->assertSame([BookingSource::Admin, '2026-11-09T10:00:00+00:00'], [$booking->source, $booking->starts_at->toIso8601String()]);
        $this->assertSame(['Test Customer', 'test@example.com', '07700900000'], [$booking->customer_name, $booking->customer->email, $booking->customer_phone]);
    }

    public function test_books_for_the_customer_the_options_give(): void
    {
        $business = $this->sampleBusiness();
        $assessment = $this->service($business, 'Physio assessment');

        $this->artisan('booking:create', [
            'business' => $business->id,
            'service' => $assessment->id,
            'start' => '2026-11-09 10:00',
            '--name' => 'Sam Taylor',
            '--email' => 'sam@example.com',
            '--phone' => '07700 900123',
        ])->assertSuccessful();

        $booking = $this->bookingOf($business);
        $this->assertSame(['Sam Taylor', 'sam@example.com', '07700 900123'], [$booking->customer_name, $booking->customer->email, $booking->customer_phone]);
    }

    public function test_trims_the_customer_options_before_checking_them(): void
    {
        $business = $this->sampleBusiness();
        $assessment = $this->service($business, 'Physio assessment');

        $this->artisan('booking:create', [
            'business' => $business->id,
            'service' => $assessment->id,
            'start' => '2026-11-09 10:00',
            '--name' => ' Sam Taylor ',
            '--email' => ' Sam@Example.com ',
            '--phone' => ' 07700 900123 ',
        ])->assertSuccessful();

        $booking = $this->bookingOf($business);
        $this->assertSame(['Sam Taylor', 'sam@example.com', '07700 900123'], [$booking->customer_name, $booking->customer->email, $booking->customer_phone]);
    }

    public function test_refuses_an_unknown_business(): void
    {
        $this->sampleBusiness();

        $this->artisan('booking:create', ['business' => '999', 'service' => '1', 'start' => '2026-11-09 10:00'])
            ->expectsOutput('There\'s no business with ID 999.')
            ->assertFailed();

        $this->assertNothingWritten();
    }

    public function test_refuses_another_businesss_service(): void
    {
        $business = $this->sampleBusiness();
        $other = app(CreateSampleBusiness::class)->handle();
        $otherService = Service::withoutGlobalScope(BusinessScope::class)->where('business_id', $other->id)->firstOrFail();

        $this->artisan('booking:create', ['business' => $business->id, 'service' => $otherService->id, 'start' => '2026-11-09 10:00'])
            ->expectsOutput("Business {$business->id} has no service with ID {$otherService->id}.")
            ->assertFailed();

        $this->assertNothingWritten();
    }

    public function test_refuses_an_inactive_service(): void
    {
        $business = $this->sampleBusiness();
        $assessment = $this->service($business, 'Physio assessment');
        $assessment->update(['is_active' => false]);

        $this->artisan('booking:create', ['business' => $business->id, 'service' => $assessment->id, 'start' => '2026-11-09 10:00'])
            ->expectsOutput("Service {$assessment->id} isn't active, so it can't be booked.")
            ->assertFailed();

        $this->assertNothingWritten();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badlyFormedStarts(): array
    {
        return [
            'no minutes' => ['2026-11-09 10'],
            'not a real date' => ['2026-11-31 10:00'],
            'not a real time' => ['2026-11-09 25:00'],
            'with seconds' => ['2026-11-09 10:00:00'],
        ];
    }

    #[DataProvider('badlyFormedStarts')]
    public function test_refuses_a_badly_formed_start(string $start): void
    {
        $business = $this->sampleBusiness();
        $assessment = $this->service($business, 'Physio assessment');

        $this->artisan('booking:create', ['business' => $business->id, 'service' => $assessment->id, 'start' => $start])
            ->expectsOutput("The start must be a real date and time written as \"YYYY-MM-DD HH:MM\", got \"{$start}\".")
            ->assertFailed();

        $this->assertNothingWritten();
    }

    public function test_refuses_a_time_the_clocks_skip(): void
    {
        $business = $this->sampleBusiness();
        $assessment = $this->service($business, 'Physio assessment');

        $this->artisan('booking:create', ['business' => $business->id, 'service' => $assessment->id, 'start' => '2027-03-28 01:30'])
            ->expectsOutput('The clocks skip 2027-03-28 01:30 in Europe/London, so it isn\'t a time there.')
            ->assertFailed();

        $this->assertNothingWritten();
    }

    public function test_refuses_an_invalid_email(): void
    {
        $business = $this->sampleBusiness();
        $assessment = $this->service($business, 'Physio assessment');

        $this->artisan('booking:create', ['business' => $business->id, 'service' => $assessment->id, 'start' => '2026-11-09 10:00', '--email' => 'sam.example.com'])
            ->expectsOutput('The email field must be a valid email address.')
            ->assertFailed();

        $this->assertNothingWritten();
    }

    public function test_refuses_a_time_already_taken_and_points_to_availability_show(): void
    {
        $business = $this->sampleBusiness();
        $assessment = $this->service($business, 'Physio assessment');
        $arguments = ['business' => $business->id, 'service' => $assessment->id, 'start' => '2026-11-09 10:00'];
        $this->artisan('booking:create', $arguments)->assertSuccessful();

        $this->artisan('booking:create', [...$arguments, '--email' => 'sam@example.com'])
            ->expectsOutput("2026-11-09 10:00 isn't one of the times offered for Physio assessment. Run php artisan availability:show {$business->id} {$assessment->id} --date=2026-11-09 to see them.")
            ->assertFailed();

        $this->assertSame(1, DB::table('bookings')->count());
        $this->assertSame(1, DB::table('customers')->count());
    }

    public function test_refuses_a_time_strictness_hides_and_suggests_availability_show(): void
    {
        $business = $this->sampleBusiness(strictness: 50);
        $assessment = $this->service($business, 'Physio assessment');

        $this->artisan('booking:create', ['business' => $business->id, 'service' => $assessment->id, 'start' => '2026-11-09 09:15'])
            ->expectsOutput("2026-11-09 09:15 isn't one of the times offered for Physio assessment. Run php artisan availability:show {$business->id} {$assessment->id} --date=2026-11-09 to see them.")
            ->assertFailed();

        $this->assertNothingWritten();
    }

    public function test_books_a_time_strictness_hides_at_another_strictness_without_saving_it(): void
    {
        $business = $this->sampleBusiness(strictness: 50);
        $assessment = $this->service($business, 'Physio assessment');

        $this->artisan('booking:create', ['business' => $business->id, 'service' => $assessment->id, 'start' => '2026-11-09 09:15', '--strictness' => '0'])
            ->expectsOutputToContain('Booked Physio assessment for Monday 9 November 2026, 09:15–10:15 (Europe/London), booking ')
            ->assertSuccessful();

        $this->assertSame('2026-11-09T09:15:00+00:00', $this->bookingOf($business)->starts_at->toIso8601String());
        $this->assertSame(50, $business->fresh()?->strictness);
    }

    public function test_suggests_availability_show_at_the_strictness_tried(): void
    {
        $business = $this->sampleBusiness(strictness: 50);
        $assessment = $this->service($business, 'Physio assessment');

        $this->artisan('booking:create', ['business' => $business->id, 'service' => $assessment->id, 'start' => '2026-11-09 09:45', '--strictness' => '100'])
            ->expectsOutput("2026-11-09 09:45 isn't one of the times offered for Physio assessment. Run php artisan availability:show {$business->id} {$assessment->id} --date=2026-11-09 --strictness=100 to see them.")
            ->assertFailed();

        $this->assertNothingWritten();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function strictnessNotAllowed(): array
    {
        return ['above 100' => ['101'], 'below 0' => ['-1'], 'not whole' => ['50.5'], 'not a number' => ['strict']];
    }

    #[DataProvider('strictnessNotAllowed')]
    public function test_refuses_a_strictness_that_is_not_a_whole_number_from_0_to_100(string $strictness): void
    {
        $business = $this->sampleBusiness();
        $assessment = $this->service($business, 'Physio assessment');

        $this->artisan('booking:create', ['business' => $business->id, 'service' => $assessment->id, 'start' => '2026-11-09 10:00', '--strictness' => $strictness])
            ->expectsOutput("The strictness must be a whole number from 0 to 100, got \"{$strictness}\".")
            ->assertFailed();

        $this->assertNothingWritten();
    }

    public function test_refuses_a_strictness_option_with_no_value_rather_than_ignoring_it(): void
    {
        $business = $this->sampleBusiness(strictness: 50);
        $assessment = $this->service($business, 'Physio assessment');

        $this->artisan('booking:create', ['business' => $business->id, 'service' => $assessment->id, 'start' => '2026-11-09 09:15', '--strictness' => null])
            ->expectsOutput('The strictness must be a whole number from 0 to 100, got "".')
            ->assertFailed();

        $this->assertNothingWritten();
    }

    public function test_refuses_to_run_in_production_before_reading_the_database(): void
    {
        $business = $this->sampleBusiness();
        $assessment = $this->service($business, 'Physio assessment');
        $this->app->detectEnvironment(fn (): string => 'production');
        DB::enableQueryLog();

        $this->artisan('booking:create', ['business' => $business->id, 'service' => $assessment->id, 'start' => '2026-11-09 10:00'])
            ->expectsOutput('booking:create writes bookings, and nothing can cancel one yet, so it doesn\'t run in production.')
            ->assertFailed();

        $this->assertSame([], DB::getQueryLog());
    }

    /**
     * The sample business, at strictness 0 unless a test gives another,
     * since most of these tests are about booking what fits, not what
     * strictness hides (07).
     */
    private function sampleBusiness(int $strictness = 0): Business
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00', 'Europe/London'));
        $business = app(CreateSampleBusiness::class)->handle();
        $business->update(['strictness' => $strictness]);

        return $business;
    }

    private function service(Business $business, string $name): Service
    {
        return app(CurrentBusiness::class)->run($business, fn (): Service => Service::query()->where('name', $name)->sole());
    }

    private function bookingOf(Business $business): Booking
    {
        return app(CurrentBusiness::class)->run($business, fn (): Booking => Booking::query()->with('customer')->sole());
    }

    private function assertNothingWritten(): void
    {
        $this->assertSame(0, DB::table('bookings')->count());
        $this->assertSame(0, DB::table('customers')->count());
    }
}
