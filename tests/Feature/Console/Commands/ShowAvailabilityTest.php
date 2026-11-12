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

    public function test_prints_each_times_waste_score_and_whether_strictness_hides_it(): void
    {
        $business = $this->sampleBusiness(strictness: 75);
        $followUp = $this->service($business, 'Physio follow-up');

        $this->artisan('availability:show', ['business' => $business->id, 'service' => $followUp->id, '--date' => '2026-11-14'])
            ->expectsOutput('Saturday 14 November 2026, Europe/London')
            ->expectsOutput('Offered times  8')
            ->expectsOutput('  09:00 09:30 10:00 10:30 11:00 11:30 12:00 12:30')
            ->expectsOutput('Hidden times   7')
            ->expectsOutput('  09:15 09:45 10:15 10:45 11:15 11:45 12:15')
            ->expectsTable(['Time', 'Shown', 'Extra waste', 'Waste', 'Stranded', 'Lost room', 'Split'], [
                ['09:00', 'Offered', 0, 36, 0, 36, 0],
                ['09:15', 'Hidden', 32, 68, 15, 48, 5],
                ['09:30', 'Offered', 5, 41, 0, 36, 5],
                ['09:45', 'Hidden', 59, 95, 0, 90, 5],
                ['10:00', 'Offered', 23, 59, 0, 54, 5],
                ['10:15', 'Hidden', 35, 71, 0, 66, 5],
                ['10:30', 'Offered', 5, 41, 0, 36, 5],
                ['10:45', 'Hidden', 41, 77, 0, 72, 5],
                ['11:00', 'Offered', 5, 41, 0, 36, 5],
                ['11:15', 'Hidden', 35, 71, 0, 66, 5],
                ['11:30', 'Offered', 23, 59, 0, 54, 5],
                ['11:45', 'Hidden', 59, 95, 0, 90, 5],
                ['12:00', 'Offered', 5, 41, 0, 36, 5],
                ['12:15', 'Hidden', 32, 68, 15, 48, 5],
                ['12:30', 'Offered', 0, 36, 0, 36, 0],
            ])
            ->assertSuccessful();
    }

    /**
     * Each case's lines, in the order they're printed.
     *
     * @return array<string, array{array<string, string>, list<string>}>
     */
    public static function strictnessTried(): array
    {
        return [
            'the business\'s own' => [[], [
                'Strictness     50: hides times with more than 60 min of extra waste',
                'Offered times  22',
                '  09:00 09:30 09:45 10:00 10:15 10:30 10:45 11:00 11:15 11:30 12:00 14:00',
                'Hidden times   2',
                '  09:15 11:45',
            ]],
            'the loosest' => [['--strictness' => '0'], [
                'Strictness     0: every time that fits is offered (from --strictness, not saved)',
                'Relaxation     48 hours, until 08:00 on Wednesday 4 November 2026: nothing is hidden at strictness 0',
                'Offered times  24',
                '  09:00 09:15 09:30 09:45 10:00 10:15 10:30 10:45 11:00 11:15 11:30 11:45',
                'Hidden times   0',
            ]],
            'just above the loosest' => [['--strictness' => '1'], [
                'Strictness     1: hides times with more than 118.8 min of extra waste (from --strictness, not saved)',
                'Offered times  24',
                'Hidden times   0',
            ]],
            'the tightest' => [['--strictness' => '100'], [
                'Strictness     100: hides times with more than 0 min of extra waste (from --strictness, not saved)',
                'Offered times  4',
                '  09:00 12:00 14:00 16:30',
                'Hidden times   20',
            ]],
        ];
    }

    /**
     * @param  array<string, string>  $option
     * @param  list<string>  $lines
     */
    #[DataProvider('strictnessTried')]
    public function test_shows_the_day_at_another_strictness_without_saving_it(array $option, array $lines): void
    {
        $business = $this->sampleBusiness(strictness: 50);
        $assessment = $this->service($business, 'Physio assessment');
        $command = $this->artisan('availability:show', ['business' => $business->id, 'service' => $assessment->id, '--date' => '2026-11-09', ...$option]);

        foreach ($lines as $line) {
            $command->expectsOutput($line);
        }

        $command->assertSuccessful();
        $this->assertSame(50, $business->fresh()?->strictness);
    }

    /**
     * From Monday 2 November at 08:00, the 48-hour window ends at 08:00 on
     * Wednesday 4 November.
     *
     * @return array<string, array{string, string}>
     */
    public static function datesAndTheRelaxationWindow(): array
    {
        return [
            'inside it' => ['2026-11-03', 'this date is inside it, so nothing is hidden'],
            'partly inside it' => ['2026-11-04', 'strictness applies to times after 08:00'],
            'after it' => ['2026-11-05', 'strictness applies all day'],
        ];
    }

    #[DataProvider('datesAndTheRelaxationWindow')]
    public function test_says_whether_the_date_is_inside_the_relaxation_window(string $date, string $effect): void
    {
        $business = $this->sampleBusiness(strictness: 50);
        $assessment = $this->service($business, 'Physio assessment');

        $this->artisan('availability:show', ['business' => $business->id, 'service' => $assessment->id, '--date' => $date])
            ->expectsOutput("Relaxation     48 hours, until 08:00 on Wednesday 4 November 2026: {$effect}")
            ->assertSuccessful();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function strictnessNotAllowed(): array
    {
        return ['above 100' => ['101'], 'below 0' => ['-1'], 'not whole' => ['50.5'], 'not a number' => ['strict']];
    }

    #[DataProvider('strictnessNotAllowed')]
    public function test_fails_for_a_strictness_that_is_not_a_whole_number_from_0_to_100(string $strictness): void
    {
        $business = $this->sampleBusiness();
        $assessment = $this->service($business, 'Physio assessment');

        $this->artisan('availability:show', ['business' => $business->id, 'service' => $assessment->id, '--date' => '2026-11-09', '--strictness' => $strictness])
            ->expectsOutput("The strictness must be a whole number from 0 to 100, got \"{$strictness}\".")
            ->assertFailed();
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
