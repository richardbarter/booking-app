<?php

namespace Tests\Feature\Console\Commands;

use App\Actions\CreateSampleBusiness;
use App\Models\Business;
use App\Models\Service;
use App\Models\TimeOff;
use App\Services\CurrentBusiness;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The sample business, created on Monday 2 November 2026 at 08:00 in
 * London, as in AvailabilityServiceTest.
 */
class ShowAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_prints_a_mondays_working_hours_free_gaps_and_offered_times(): void
    {
        $business = $this->sampleBusiness();
        $assessment = $this->service($business, 'Physio assessment');

        $this->artisan('availability:show', ['business' => $business->id, 'service' => $assessment->id, '--date' => '2026-11-09'])
            ->expectsOutput("Alex Morgan Physio & Sports Massage, business {$business->id}")
            ->expectsOutput("Physio assessment, service {$assessment->id}: 60 minutes, no buffers")
            ->expectsOutput('Monday 9 November 2026, Europe/London')
            ->expectsOutput('Working hours  09:00–13:00, 14:00–17:30')
            ->expectsOutput('Free gaps      09:00–13:00, 14:00–17:30')
            ->expectsOutput('Stranded       none')
            ->expectsOutput('Fragmented     none')
            ->expectsOutput('Offered times  24')
            ->expectsOutput('  09:00 09:15 09:30 09:45 10:00 10:15 10:30 10:45 11:00 11:15 11:30 11:45')
            ->expectsOutput('  12:00 14:00 14:15 14:30 14:45 15:00 15:15 15:30 15:45 16:00 16:15 16:30')
            ->assertSuccessful();
    }

    public function test_prints_each_offered_times_waste_score(): void
    {
        $business = $this->sampleBusiness();
        $followUp = $this->service($business, 'Physio follow-up');

        $this->artisan('availability:show', ['business' => $business->id, 'service' => $followUp->id, '--date' => '2026-11-14'])
            ->expectsOutput('Saturday 14 November 2026, Europe/London')
            ->expectsTable(['Time', 'Extra waste', 'Waste', 'Stranded', 'Lost room', 'Split'], [
                ['09:00', 0, 36, 0, 36, 0],
                ['09:15', 32, 68, 15, 48, 5],
                ['09:30', 5, 41, 0, 36, 5],
                ['09:45', 59, 95, 0, 90, 5],
                ['10:00', 23, 59, 0, 54, 5],
                ['10:15', 35, 71, 0, 66, 5],
                ['10:30', 5, 41, 0, 36, 5],
                ['10:45', 41, 77, 0, 72, 5],
                ['11:00', 5, 41, 0, 36, 5],
                ['11:15', 35, 71, 0, 66, 5],
                ['11:30', 23, 59, 0, 54, 5],
                ['11:45', 59, 95, 0, 90, 5],
                ['12:00', 5, 41, 0, 36, 5],
                ['12:15', 32, 68, 15, 48, 5],
                ['12:30', 0, 36, 0, 36, 0],
            ])
            ->assertSuccessful();
    }

    public function test_prints_the_days_stranded_and_fragmented_time(): void
    {
        $business = $this->sampleBusiness();
        $followUp = $this->service($business, 'Physio follow-up');
        app(CurrentBusiness::class)->run($business, fn () => $business->practitioners()->sole()->timeOff()->create([
            'starts_at' => CarbonImmutable::parse('2026-11-09 09:15', 'Europe/London'),
            'ends_at' => CarbonImmutable::parse('2026-11-09 12:00', 'Europe/London'),
        ]));

        $this->artisan('availability:show', ['business' => $business->id, 'service' => $followUp->id, '--date' => '2026-11-09'])
            ->expectsOutput('Free gaps      09:00–09:15, 12:00–13:00, 14:00–17:30')
            ->expectsOutput('Stranded       15 min: 09:00–09:15')
            ->expectsOutput('Fragmented     60 min: 12:00–13:00')
            ->assertSuccessful();
    }

    public function test_shows_time_off_as_a_missing_free_gap_without_its_reason(): void
    {
        $business = $this->sampleBusiness();
        $assessment = $this->service($business, 'Physio assessment');
        app(CurrentBusiness::class)->run($business, fn () => TimeOff::query()->sole()->update(['reason' => 'Hospital appointment']));

        $this->artisan('availability:show', ['business' => $business->id, 'service' => $assessment->id, '--date' => '2026-11-10'])
            ->expectsOutput('Working hours  09:00–13:00, 14:00–17:30')
            ->expectsOutput('Free gaps      09:00–13:00')
            ->expectsOutput('Offered times  13')
            ->doesntExpectOutputToContain('Hospital')
            ->assertSuccessful();
    }

    public function test_describes_a_services_buffers(): void
    {
        $business = $this->sampleBusiness();
        $service = app(CurrentBusiness::class)->run($business, fn (): Service => $business->services()->create([
            'name' => 'Massage with set-up',
            'duration_minutes' => 90,
            'buffer_before_minutes' => 15,
            'buffer_after_minutes' => 10,
            'price_pence' => 9000,
        ]));

        $this->artisan('availability:show', ['business' => $business->id, 'service' => $service->id, '--date' => '2026-11-09'])
            ->expectsOutput("Massage with set-up, service {$service->id}: 90 minutes, 15 minutes before and 10 after")
            ->assertSuccessful();
    }

    public function test_shows_the_seconds_of_a_gap_that_starts_part_way_through_a_minute(): void
    {
        $business = $this->sampleBusiness();
        $assessment = $this->service($business, 'Physio assessment');
        app(CurrentBusiness::class)->run($business, fn () => $business->practitioners()->sole()->timeOff()->create([
            'starts_at' => CarbonImmutable::parse('2026-11-09 09:00', 'Europe/London'),
            'ends_at' => CarbonImmutable::parse('2026-11-09 10:00:30', 'Europe/London'),
        ]));

        $this->artisan('availability:show', ['business' => $business->id, 'service' => $assessment->id, '--date' => '2026-11-09'])
            ->expectsOutput('Free gaps      10:00:30–13:00, 14:00–17:30')
            ->expectsOutput('  10:15 10:30 10:45 11:00 11:15 11:30 11:45 12:00 14:00 14:15 14:30 14:45')
            ->assertSuccessful();
    }

    public function test_shows_hours_to_midnight_as_ending_at_24_00(): void
    {
        $business = $this->sampleBusiness();
        $assessment = $this->service($business, 'Physio assessment');
        app(CurrentBusiness::class)->run($business, fn () => $business->practitioners()->sole()->editedDays()->create([
            'date' => '2026-11-12',
            'hours' => [['18:00', '24:00']],
        ]));

        $this->artisan('availability:show', ['business' => $business->id, 'service' => $assessment->id, '--date' => '2026-11-12'])
            ->expectsOutput('Working hours  18:00–24:00')
            ->assertSuccessful();
    }

    public function test_says_none_for_a_day_with_no_hours(): void
    {
        $business = $this->sampleBusiness();
        $assessment = $this->service($business, 'Physio assessment');

        $this->artisan('availability:show', ['business' => $business->id, 'service' => $assessment->id, '--date' => '2026-11-08'])
            ->expectsOutput('Working hours  none')
            ->expectsOutput('Free gaps      none')
            ->expectsOutput('Stranded       none')
            ->expectsOutput('Offered times  0')
            ->doesntExpectOutputToContain('Extra waste')
            ->assertSuccessful();
    }

    public function test_defaults_to_today_in_the_business_time_zone(): void
    {
        $business = $this->sampleBusiness();
        $assessment = $this->service($business, 'Physio assessment');
        $business->update(['timezone' => 'Pacific/Auckland']);
        $this->travelTo(CarbonImmutable::parse('2026-11-02 12:30', 'UTC'));

        $this->artisan('availability:show', ['business' => $business->id, 'service' => $assessment->id])
            ->expectsOutput('Tuesday 3 November 2026, Pacific/Auckland')
            ->assertSuccessful();
    }

    public function test_leaves_out_todays_times_inside_the_minimum_notice(): void
    {
        $business = $this->sampleBusiness();
        $assessment = $this->service($business, 'Physio assessment');

        $this->artisan('availability:show', ['business' => $business->id, 'service' => $assessment->id])
            ->expectsOutput('Monday 2 November 2026, Europe/London')
            ->expectsOutput('Offered times  20')
            ->expectsOutput('  10:00 10:15 10:30 10:45 11:00 11:15 11:30 11:45 12:00 14:00 14:15 14:30')
            ->assertSuccessful();
    }

    public function test_lists_the_business_services_when_no_service_is_given(): void
    {
        $business = $this->sampleBusiness();
        $ids = app(CurrentBusiness::class)->run($business, function (): array {
            Service::query()->where('name', 'Sports massage, 90 minutes')->update(['is_active' => false]);

            return Service::query()->orderBy('id')->pluck('id')->all();
        });

        $this->artisan('availability:show', ['business' => $business->id])
            ->expectsTable(['ID', 'Name', 'Length', 'Buffer before', 'Buffer after', 'Room weight', 'Active'], [
                [$ids[0], 'Physio follow-up', '30 min', '0 min', '0 min', 10, 'Yes'],
                [$ids[1], 'Physio assessment', '60 min', '0 min', '0 min', 10, 'Yes'],
                [$ids[2], 'Sports massage, 90 minutes', '90 min', '0 min', '0 min', 5, 'No'],
            ])
            ->assertSuccessful();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function businessesThatDoNotExist(): array
    {
        return ['an unknown ID' => ['999999'], 'not a number' => ['abc'], 'zero' => ['0']];
    }

    #[DataProvider('businessesThatDoNotExist')]
    public function test_fails_for_an_unknown_business(string $business): void
    {
        $this->sampleBusiness();

        $this->artisan('availability:show', ['business' => $business])
            ->expectsOutput("There's no business with ID {$business}.")
            ->assertFailed();
    }

    public function test_fails_for_another_business_service(): void
    {
        $business = $this->sampleBusiness();
        $other = app(CreateSampleBusiness::class)->handle();
        $otherService = $this->service($other, 'Physio assessment');

        $this->artisan('availability:show', ['business' => $business->id, 'service' => $otherService->id])
            ->expectsOutput("Business {$business->id} has no service with ID {$otherService->id}.")
            ->assertFailed();
    }

    public function test_fails_for_a_service_id_that_is_not_a_number(): void
    {
        $business = $this->sampleBusiness();

        $this->artisan('availability:show', ['business' => $business->id, 'service' => 'assessment'])
            ->expectsOutput("Business {$business->id} has no service with ID assessment.")
            ->assertFailed();
    }

    public function test_fails_for_an_inactive_service(): void
    {
        $business = $this->sampleBusiness();
        $assessment = $this->service($business, 'Physio assessment');
        app(CurrentBusiness::class)->run($business, fn () => $assessment->update(['is_active' => false]));

        $this->artisan('availability:show', ['business' => $business->id, 'service' => $assessment->id, '--date' => '2026-11-09'])
            ->expectsOutput("Service {$assessment->id} isn't active, so it can't be booked.")
            ->assertFailed();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badlyFormedDates(): array
    {
        return [
            'day first' => ['09/11/2026'],
            'no thirteenth month' => ['2026-13-01'],
            'a date that does not exist' => ['2026-02-30'],
            'in words' => ['9 November'],
            'empty' => [''],
        ];
    }

    #[DataProvider('badlyFormedDates')]
    public function test_fails_for_a_badly_formed_date(string $date): void
    {
        $business = $this->sampleBusiness();
        $assessment = $this->service($business, 'Physio assessment');

        $this->artisan('availability:show', ['business' => $business->id, 'service' => $assessment->id, '--date' => $date])
            ->expectsOutput("The date must be a real date written as YYYY-MM-DD, got \"{$date}\".")
            ->assertFailed();
    }

    /**
     * The sample business, at strictness 0 unless a test gives another,
     * since most of these tests are about what fits, not what strictness
     * hides (07).
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
}
