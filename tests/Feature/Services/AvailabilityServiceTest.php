<?php

namespace Tests\Feature\Services;

use App\Actions\CreateSampleBusiness;
use App\Enums\BookingStatus;
use App\Exceptions\MissingCurrentBusiness;
use App\Exceptions\ServiceNotBookable;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Scopes\BusinessScope;
use App\Models\Service;
use App\Models\WorkingPattern;
use App\Scheduling\Availability;
use App\Scheduling\Slot;
use App\Scheduling\TimeRange;
use App\Services\AvailabilityService;
use App\Services\CurrentBusiness;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The sample business, created on Monday 2 November 2026 at 08:00 in
 * London, the start of its pattern's week 1. Monday 9 November starts
 * week 2: Tuesday 10 November has time off from 14:00, and Wednesday 11
 * November is an edited day from 10:00 to 16:00. Times are in London.
 */
class AvailabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_offers_the_physio_assessment_every_15_minutes_on_a_monday(): void
    {
        $business = $this->sampleBusiness();

        $offered = $this->offered($business, 'Physio assessment', '2026-11-09');

        $this->assertSame([...$this->every15('09:00', '12:00'), ...$this->every15('14:00', '16:30')], $offered);
        $this->assertCount(24, $offered);
    }

    public function test_offers_only_the_morning_on_the_tuesday_with_time_off_from_14_00(): void
    {
        $business = $this->sampleBusiness();

        $this->assertSame($this->every15('09:00', '12:00'), $this->offered($business, 'Physio assessment', '2026-11-10'));
    }

    public function test_offers_the_edited_wednesdays_hours(): void
    {
        $business = $this->sampleBusiness();

        $offered = $this->offered($business, 'Physio assessment', '2026-11-11');

        $this->assertSame($this->every15('10:00', '15:00'), $offered);
        $this->assertCount(21, $offered);
    }

    public function test_offers_the_90_minute_massage_where_it_fits(): void
    {
        $business = $this->sampleBusiness();

        $offered = $this->offered($business, 'Sports massage, 90 minutes', '2026-11-09');

        $this->assertSame([...$this->every15('09:00', '11:30'), ...$this->every15('14:00', '16:00')], $offered);
        $this->assertCount(20, $offered);
    }

    public function test_keeps_a_services_buffers_inside_the_hours(): void
    {
        $business = $this->sampleBusiness();
        $business->services()->create([
            'name' => 'Massage with set-up and clean-up',
            'duration_minutes' => 90,
            'buffer_before_minutes' => 15,
            'buffer_after_minutes' => 15,
            'price_pence' => 9000,
        ]);

        $offered = $this->offered($business, 'Massage with set-up and clean-up', '2026-11-09');

        $this->assertSame([...$this->every15('09:15', '11:15'), ...$this->every15('14:15', '15:45')], $offered);
    }

    public function test_offers_nothing_inside_the_two_hours_notice_today(): void
    {
        $business = $this->sampleBusiness();

        $offered = $this->offered($business, 'Physio assessment', '2026-11-02');

        $this->assertSame([...$this->every15('10:00', '12:00'), ...$this->every15('14:00', '16:30')], $offered);
    }

    public function test_moving_the_time_moves_which_of_todays_times_are_inside_the_notice(): void
    {
        $business = $this->sampleBusiness();
        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:10', 'Europe/London'));

        $offered = $this->offered($business, 'Physio assessment', '2026-11-02');

        $this->assertSame('11:15', $offered[0]);
    }

    public function test_time_off_starting_before_the_range_or_ending_after_it_blocks_the_dates_inside_it(): void
    {
        $business = $this->sampleBusiness();
        $practitioner = $business->practitioners()->sole();
        $practitioner->timeOff()->create([
            'starts_at' => CarbonImmutable::parse('2026-11-08 12:00', 'Europe/London'),
            'ends_at' => CarbonImmutable::parse('2026-11-09 12:00', 'Europe/London'),
        ]);
        $practitioner->timeOff()->create([
            'starts_at' => CarbonImmutable::parse('2026-11-09 16:00', 'Europe/London'),
            'ends_at' => CarbonImmutable::parse('2026-11-11 10:00', 'Europe/London'),
        ]);

        $offered = $this->offered($business, 'Physio assessment', '2026-11-09');

        $this->assertSame(['12:00', ...$this->every15('14:00', '15:00')], $offered);
    }

    public function test_finds_time_off_at_the_end_of_the_range_whatever_the_connections_time_zone(): void
    {
        $business = $this->sampleBusiness();
        $business->practitioners()->sole()->timeOff()->create([
            'starts_at' => CarbonImmutable::parse('2026-11-09 16:00', 'Europe/London'),
            'ends_at' => CarbonImmutable::parse('2026-11-11 10:00', 'Europe/London'),
        ]);
        DB::statement("SET TIME ZONE 'Pacific/Auckland'");

        $offered = $this->offered($business, 'Physio assessment', '2026-11-09');

        $this->assertSame([...$this->every15('09:00', '12:00'), ...$this->every15('14:00', '15:00')], $offered);
    }

    /**
     * @return array<string, array{BookingStatus, list<array{string, string}>}>
     */
    public static function bookingStatuses(): array
    {
        $withTheBooking = [['09:00', '10:45'], ['11:45', '13:00'], ['14:00', '17:30']];

        return [
            'confirmed' => [BookingStatus::Confirmed, $withTheBooking],
            'no-show' => [BookingStatus::NoShow, $withTheBooking],
            'cancelled' => [BookingStatus::Cancelled, [['09:00', '13:00'], ['14:00', '17:30']]],
        ];
    }

    /**
     * @param  list<array{string, string}>  $expectedGaps
     */
    #[DataProvider('bookingStatuses')]
    public function test_a_booking_blocks_its_time_and_buffers_unless_it_is_cancelled(BookingStatus $status, array $expectedGaps): void
    {
        $business = $this->sampleBusiness();
        $service = $business->services()->create([
            'name' => 'Follow-up with set-up and clean-up',
            'duration_minutes' => 30,
            'buffer_before_minutes' => 15,
            'buffer_after_minutes' => 15,
            'price_pence' => 5000,
        ]);
        Booking::factory()
            ->for($business->practitioners()->sole())
            ->for($service)
            ->at(CarbonImmutable::parse('2026-11-09 11:00', 'Europe/London'))
            ->create(['status' => $status]);

        $gaps = $this->freeGapsOn($business, '2026-11-09');

        $this->assertSame($expectedGaps, $gaps);
    }

    public function test_a_booking_starting_before_the_range_or_ending_after_it_blocks_the_dates_inside_it(): void
    {
        $business = $this->sampleBusiness();
        $this->blockWithBooking($business, '2026-11-08 23:00', '2026-11-09 10:00');
        $this->blockWithBooking($business, '2026-11-09 16:00', '2026-11-10 08:00');

        $offered = $this->offered($business, 'Physio assessment', '2026-11-09');

        $this->assertSame([...$this->every15('10:00', '12:00'), ...$this->every15('14:00', '15:00')], $offered);
    }

    public function test_finds_a_booking_at_the_end_of_the_range_whatever_the_connections_time_zone(): void
    {
        $business = $this->sampleBusiness();
        $this->blockWithBooking($business, '2026-11-09 16:00', '2026-11-09 17:00');
        DB::statement("SET TIME ZONE 'Pacific/Auckland'");

        $offered = $this->offered($business, 'Physio assessment', '2026-11-09');

        $this->assertSame([...$this->every15('09:00', '12:00'), ...$this->every15('14:00', '15:00')], $offered);
    }

    public function test_another_business_booking_at_the_same_times_changes_nothing(): void
    {
        $business = $this->sampleBusiness();
        $other = app(CreateSampleBusiness::class)->handle();
        app(CurrentBusiness::class)->run($other, fn () => $this->blockWithBooking($other, '2026-11-09 09:00', '2026-11-09 17:30'));

        $offered = $this->offered($business, 'Physio assessment', '2026-11-09');

        $this->assertCount(24, $offered);
    }

    public function test_uses_each_pattern_only_for_its_own_dates(): void
    {
        $business = $this->sampleBusiness();
        WorkingPattern::query()->sole()->update(['ends_on' => '2026-11-10']);
        $earlyMornings = array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], [['08:00', '10:00']]);
        $business->practitioners()->sole()->workingPatterns()->create([
            'starts_on' => '2026-11-12',
            'ends_on' => null,
            'cycle_starts_on' => '2026-11-09',
            'weeks' => [$earlyMornings],
        ]);

        $availability = $this->availability($business, 'Physio assessment', '2026-11-10', '2026-11-13');

        $this->assertSame([
            '2026-11-10' => [['09:00', '13:00'], ['14:00', '17:30']],
            '2026-11-11' => [['10:00', '16:00']],
            '2026-11-12' => [['08:00', '10:00']],
            '2026-11-13' => [['08:00', '10:00']],
        ], $this->workingTimesByDate($availability));
    }

    public function test_runs_the_same_five_queries_for_one_date_and_for_sixty(): void
    {
        $business = $this->sampleBusiness();
        $service = $this->service('Physio assessment');
        $availability = app(AvailabilityService::class);
        DB::enableQueryLog();

        $availability->forService($business, $service, CarbonImmutable::parse('2026-11-09'), CarbonImmutable::parse('2026-11-09'));
        $forOneDate = count(DB::getQueryLog());
        DB::flushQueryLog();
        $availability->forService($business, $service, CarbonImmutable::parse('2026-11-02'), CarbonImmutable::parse('2026-12-31'));
        $forSixtyDates = count(DB::getQueryLog());

        $this->assertSame(5, $forOneDate);
        $this->assertSame(5, $forSixtyDates);
    }

    public function test_another_business_time_off_at_the_same_times_changes_nothing(): void
    {
        $business = $this->sampleBusiness();
        $other = app(CreateSampleBusiness::class)->handle();
        app(CurrentBusiness::class)->run($other, fn () => $other->practitioners()->sole()->timeOff()->create([
            'starts_at' => CarbonImmutable::parse('2026-11-09 09:00', 'Europe/London'),
            'ends_at' => CarbonImmutable::parse('2026-11-09 17:30', 'Europe/London'),
        ]));

        $offered = $this->offered($business, 'Physio assessment', '2026-11-09');

        $this->assertCount(24, $offered);
    }

    public function test_rejects_an_inactive_service(): void
    {
        $business = $this->sampleBusiness();
        $service = $this->service('Physio assessment');
        $service->update(['is_active' => false]);

        $this->expectException(ServiceNotBookable::class);
        $this->expectExceptionMessage("Service {$service->id} isn't active");

        app(AvailabilityService::class)->forService($business, $service, CarbonImmutable::parse('2026-11-09'), CarbonImmutable::parse('2026-11-09'));
    }

    public function test_rejects_another_business_service(): void
    {
        $business = $this->sampleBusiness();
        $other = app(CreateSampleBusiness::class)->handle();
        $otherService = Service::withoutGlobalScope(BusinessScope::class)->where('business_id', $other->id)->firstOrFail();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Service {$otherService->id} belongs to another business, not business {$business->id}");

        app(AvailabilityService::class)->forService($business, $otherService, CarbonImmutable::parse('2026-11-09'), CarbonImmutable::parse('2026-11-09'));
    }

    public function test_rejects_a_business_that_is_not_the_current_one(): void
    {
        $business = $this->sampleBusiness();
        $service = $this->service('Physio assessment');
        $other = app(CreateSampleBusiness::class)->handle();
        app(CurrentBusiness::class)->set($other);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("asked for business {$business->id}, but the current business is {$other->id}");

        app(AvailabilityService::class)->forService($business, $service, CarbonImmutable::parse('2026-11-09'), CarbonImmutable::parse('2026-11-09'));
    }

    public function test_rejects_a_search_with_no_current_business(): void
    {
        $business = $this->sampleBusiness();
        $service = $this->service('Physio assessment');
        app(CurrentBusiness::class)->forget();

        $this->expectException(MissingCurrentBusiness::class);

        app(AvailabilityService::class)->forService($business, $service, CarbonImmutable::parse('2026-11-09'), CarbonImmutable::parse('2026-11-09'));
    }

    public function test_rejects_an_invalid_date_range_before_querying_anything(): void
    {
        $business = $this->sampleBusiness();
        $service = $this->service('Physio assessment');
        DB::enableQueryLog();

        try {
            app(AvailabilityService::class)->forService($business, $service, CarbonImmutable::parse('2026-11-10'), CarbonImmutable::parse('2026-11-09'));
            $this->fail('An invalid date range was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('can\'t end before it starts', $exception->getMessage());
        }

        $this->assertSame([], DB::getQueryLog());
    }

    /**
     * The sample business, made the current business.
     */
    private function sampleBusiness(): Business
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00', 'Europe/London'));
        $business = app(CreateSampleBusiness::class)->handle();
        app(CurrentBusiness::class)->set($business);

        return $business;
    }

    private function service(string $name): Service
    {
        return Service::query()->where('name', $name)->sole();
    }

    private function availability(Business $business, string $serviceName, string $from, string $to): Availability
    {
        return app(AvailabilityService::class)->forService(
            $business,
            $this->service($serviceName),
            CarbonImmutable::parse($from),
            CarbonImmutable::parse($to),
        );
    }

    /**
     * @return list<string>
     */
    private function offered(Business $business, string $serviceName, string $date): array
    {
        $day = $this->availability($business, $serviceName, $date, $date)->day($date);
        $this->assertNotNull($day);

        return array_map(fn (Slot $slot): string => $slot->startsAt->setTimezone('Europe/London')->format('H:i'), $day->slots);
    }

    /**
     * A confirmed booking with no buffers whose appointment covers the
     * times, in London. Its length needn't match the service's, so it can
     * run past the dates searched.
     */
    private function blockWithBooking(Business $business, string $from, string $until): Booking
    {
        $startsAt = CarbonImmutable::parse($from, 'Europe/London');
        $endsAt = CarbonImmutable::parse($until, 'Europe/London');

        return Booking::factory()
            ->for($business->practitioners()->sole())
            ->for($business->services()->firstOrFail())
            ->create(['starts_at' => $startsAt, 'ends_at' => $endsAt, 'blocked_from' => $startsAt, 'blocked_until' => $endsAt]);
    }

    /**
     * @return list<array{string, string}>
     */
    private function freeGapsOn(Business $business, string $date): array
    {
        $day = $this->availability($business, 'Physio assessment', $date, $date)->day($date);
        $this->assertNotNull($day);

        return array_map(fn (TimeRange $gap): array => [
            $gap->start->setTimezone('Europe/London')->format('H:i'),
            $gap->end->setTimezone('Europe/London')->format('H:i'),
        ], $day->freeGaps);
    }

    /**
     * @return array<string, list<array{string, string}>>
     */
    private function workingTimesByDate(Availability $availability): array
    {
        $times = [];

        foreach ($availability->days as $day) {
            $times[$day->date] = array_map(fn (TimeRange $range): array => [
                $range->start->setTimezone('Europe/London')->format('H:i'),
                $range->end->setTimezone('Europe/London')->format('H:i'),
            ], $day->workingTimes);
        }

        return $times;
    }

    /**
     * Every quarter-hour from the first time to the last, both included.
     *
     * @return list<string>
     */
    private function every15(string $first, string $last): array
    {
        $times = [];

        for ($time = CarbonImmutable::parse($first); $time <= CarbonImmutable::parse($last); $time = $time->addMinutes(15)) {
            $times[] = $time->format('H:i');
        }

        return $times;
    }
}
