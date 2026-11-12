<?php

namespace Tests\Feature\Actions;

use App\Actions\CreateBooking;
use App\Actions\CreateSampleBusiness;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Exceptions\MissingCurrentBusiness;
use App\Exceptions\ServiceNotBookable;
use App\Exceptions\SlotNotAvailable;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Scopes\BusinessScope;
use App\Models\Service;
use App\Services\AvailabilityService;
use App\Services\CurrentBusiness;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The sample business, created on Monday 2 November 2026 at 08:00 in
 * London, as in 04's tests. Monday 9 November has hours 09:00–13:00 and
 * 14:00–17:30, Tuesday 10 November has time off from 14:00, and the
 * booking window runs from 10:00 today (2 hours' notice) to 31 January
 * (90 days). Times are in London, which matches UTC in November.
 */
class CreateBookingTest extends TestCase
{
    use RefreshDatabase;

    private const array SAM = ['name' => 'Sam Taylor', 'email' => 'sam@example.com', 'phone' => '07700 900123'];

    public function test_saves_the_booking_with_the_slots_times_and_the_services_price(): void
    {
        $business = $this->sampleBusiness();
        $assessment = $this->service('Physio assessment');

        $booking = $this->book($business, $assessment, '2026-11-09 10:00', source: BookingSource::BookingPage);

        $saved = Booking::query()->sole();
        $this->assertTrue($saved->is($booking));
        $this->assertSame('2026-11-09T10:00:00+00:00', $saved->starts_at->toIso8601String());
        $this->assertSame('2026-11-09T11:00:00+00:00', $saved->ends_at->toIso8601String());
        $this->assertSame('2026-11-09T10:00:00+00:00', $saved->blocked_from->toIso8601String());
        $this->assertSame('2026-11-09T11:00:00+00:00', $saved->blocked_until->toIso8601String());
        $this->assertSame(7000, $saved->price_pence);
        $this->assertSame(BookingStatus::Confirmed, $saved->status);
        $this->assertSame(BookingSource::BookingPage, $saved->source);
        $this->assertSame([$business->id, $business->practitioners()->sole()->id, $assessment->id], [$saved->business_id, $saved->practitioner_id, $saved->service_id]);
        $this->assertSame(['Sam Taylor', '07700 900123'], [$saved->customer_name, $saved->customer_phone]);
    }

    public function test_creates_the_customer_with_the_details_trimmed_and_the_email_in_lower_case(): void
    {
        $business = $this->sampleBusiness();

        $booking = $this->book($business, $this->service('Physio assessment'), '2026-11-09 10:00', [
            'name' => ' Sam Taylor ',
            'email' => ' Sam@Example.COM ',
            'phone' => ' 07700 900123 ',
        ]);

        $customer = Customer::query()->sole();
        $this->assertSame($customer->id, $booking->customer_id);
        $this->assertSame(['Sam Taylor', 'sam@example.com', '07700 900123'], [$customer->name, $customer->email, $customer->phone]);
        $this->assertSame(['Sam Taylor', '07700 900123'], [$booking->customer_name, $booking->customer_phone]);
    }

    public function test_books_a_start_given_in_a_zone_where_it_falls_on_another_date(): void
    {
        $business = $this->sampleBusiness();
        $startsAt = CarbonImmutable::parse('2026-11-10 00:00', 'Pacific/Kiritimati');

        $booking = app(CreateBooking::class)->handle($business, $this->service('Physio assessment'), $startsAt, self::SAM, BookingSource::Admin);

        $this->assertSame('2026-11-09T10:00:00+00:00', $booking->starts_at->toIso8601String());
    }

    public function test_a_repeat_email_uses_the_same_customer_without_changing_their_details(): void
    {
        $business = $this->sampleBusiness();
        $first = $this->book($business, $this->service('Physio assessment'), '2026-11-09 10:00');

        $second = $this->book($business, $this->service('Physio follow-up'), '2026-11-09 11:00', [
            'name' => 'Samuel Taylor',
            'email' => ' SAM@example.com',
            'phone' => '07700 900456',
        ]);

        $customer = Customer::query()->sole();
        $this->assertSame([$customer->id, $customer->id], [$first->customer_id, $second->customer_id]);
        $this->assertSame(['Sam Taylor', '07700 900123'], [$customer->name, $customer->phone]);
        $this->assertSame(['Samuel Taylor', '07700 900456'], [$second->customer_name, $second->customer_phone]);
    }

    public function test_finds_a_returning_customer_without_trying_to_insert_them_again(): void
    {
        $business = $this->sampleBusiness();
        $this->book($business, $this->service('Physio assessment'), '2026-11-09 10:00');
        $customerInserts = 0;
        DB::beforeExecuting(function (string $query) use (&$customerInserts): void {
            $customerInserts += str_starts_with($query, 'insert into "customers"') ? 1 : 0;
        });

        $this->book($business, $this->service('Physio follow-up'), '2026-11-09 11:00', [...self::SAM, 'email' => 'SAM@example.com']);

        $this->assertSame(0, $customerInserts);
    }

    public function test_books_a_time_starting_as_another_booking_ends(): void
    {
        $business = $this->sampleBusiness();
        $this->book($business, $this->service('Physio assessment'), '2026-11-09 10:00');

        $followUp = $this->book($business, $this->service('Physio follow-up'), '2026-11-09 11:00');

        $this->assertModelExists($followUp);
    }

    public function test_saves_the_buffers_and_keeps_them_free_of_other_bookings(): void
    {
        $business = $this->sampleBusiness();
        $withCleanUp = $business->services()->create(['name' => 'Follow-up with clean-up', 'duration_minutes' => 30, 'buffer_after_minutes' => 15, 'price_pence' => 5000]);
        $booking = $this->book($business, $withCleanUp, '2026-11-09 10:00');

        $this->assertThrows(fn () => $this->book($business, $withCleanUp, '2026-11-09 10:30'), SlotNotAvailable::class);
        $next = $this->book($business, $withCleanUp, '2026-11-09 10:45');

        $this->assertSame('2026-11-09T10:45:00+00:00', $booking->blocked_until->toIso8601String());
        $this->assertModelExists($next);
    }

    public function test_refuses_a_time_already_taken_and_writes_nothing(): void
    {
        $business = $this->sampleBusiness();
        $this->book($business, $this->service('Physio assessment'), '2026-11-09 10:00');

        $this->assertRefusedWithNothingWritten(fn () => $this->book($business, $this->service('Physio follow-up'), '2026-11-09 10:30', [
            'name' => 'Jo Patel',
            'email' => 'jo@example.com',
            'phone' => '07700 900789',
        ]));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function timesNotOffered(): array
    {
        return [
            'off the slot interval' => ['2026-11-09 09:07'],
            'with seconds' => ['2026-11-09 10:00:30'],
            'outside working hours' => ['2026-11-09 18:00'],
            'running into lunch' => ['2026-11-09 12:30'],
            'during time off' => ['2026-11-10 15:00'],
            'inside the minimum notice' => ['2026-11-02 09:00'],
            'after the last bookable date' => ['2027-02-01 10:00'],
        ];
    }

    #[DataProvider('timesNotOffered')]
    public function test_refuses_a_time_that_is_not_offered_and_writes_nothing(string $startsAt): void
    {
        $business = $this->sampleBusiness();

        $this->assertRefusedWithNothingWritten(fn () => $this->book($business, $this->service('Physio assessment'), $startsAt));
    }

    public function test_a_cancelled_bookings_time_can_be_booked_again(): void
    {
        $business = $this->sampleBusiness();
        $cancelled = $this->book($business, $this->service('Physio assessment'), '2026-11-09 10:00');
        $cancelled->update(['status' => BookingStatus::Cancelled]);

        $again = $this->book($business, $this->service('Physio assessment'), '2026-11-09 10:00');

        $this->assertModelExists($again);
    }

    public function test_refuses_an_inactive_service(): void
    {
        $business = $this->sampleBusiness();
        $service = $this->service('Physio assessment');
        $service->update(['is_active' => false]);

        $this->expectException(ServiceNotBookable::class);

        $this->book($business, $service, '2026-11-09 10:00');
    }

    public function test_refuses_another_businesss_service(): void
    {
        $business = $this->sampleBusiness();
        $other = app(CreateSampleBusiness::class)->handle();
        $otherService = Service::withoutGlobalScope(BusinessScope::class)->where('business_id', $other->id)->firstOrFail();

        $this->expectException(InvalidArgumentException::class);

        $this->book($business, $otherService, '2026-11-09 10:00');
    }

    public function test_refuses_a_business_that_is_not_the_current_one_and_writes_nothing(): void
    {
        $business = $this->sampleBusiness();
        $service = $this->service('Physio assessment');
        $other = app(CreateSampleBusiness::class)->handle();
        app(CurrentBusiness::class)->set($other);

        $this->assertThrows(fn () => $this->book($business, $service, '2026-11-09 10:00'), LogicException::class, "asked for business {$business->id}, but the current business is {$other->id}");

        $this->assertSame(0, DB::table('bookings')->count());
        $this->assertSame(0, DB::table('customers')->count());
    }

    public function test_refuses_a_booking_with_no_current_business(): void
    {
        $business = $this->sampleBusiness();
        $service = $this->service('Physio assessment');
        app(CurrentBusiness::class)->forget();

        $this->expectException(MissingCurrentBusiness::class);

        $this->book($business, $service, '2026-11-09 10:00');
    }

    public function test_never_uses_or_changes_another_businesss_customer_with_the_same_email(): void
    {
        $business = $this->sampleBusiness();
        $other = app(CreateSampleBusiness::class)->handle();
        $othersCustomer = app(CurrentBusiness::class)->run($other, fn (): Customer => $other->customers()->create(self::SAM));

        $booking = $this->book($business, $this->service('Physio assessment'), '2026-11-09 10:00', [...self::SAM, 'name' => 'Sam T']);

        $this->assertNotSame($othersCustomer->id, $booking->customer_id);
        $this->assertSame($business->id, Customer::query()->sole()->business_id);
        $this->assertSame('Sam Taylor', Customer::withoutGlobalScope(BusinessScope::class)->findOrFail($othersCustomer->id)->name);
    }

    public function test_later_changes_to_the_service_do_not_change_the_booking(): void
    {
        $business = $this->sampleBusiness();
        $assessment = $this->service('Physio assessment');
        $booking = $this->book($business, $assessment, '2026-11-09 10:00');

        $assessment->update(['duration_minutes' => 90, 'buffer_before_minutes' => 15, 'buffer_after_minutes' => 15, 'price_pence' => 9000]);

        $saved = Booking::query()->findOrFail($booking->id);
        $this->assertSame(
            ['2026-11-09T10:00:00+00:00', '2026-11-09T11:00:00+00:00', '2026-11-09T10:00:00+00:00', '2026-11-09T11:00:00+00:00', 7000],
            [$saved->starts_at->toIso8601String(), $saved->ends_at->toIso8601String(), $saved->blocked_from->toIso8601String(), $saved->blocked_until->toIso8601String(), $saved->price_pence],
        );
    }

    /**
     * The race the lock prevents, reached without two processes: the check
     * still offers a time that another booking has taken, as if that
     * booking landed just after the check, so only the constraint stops it.
     */
    public function test_turns_the_databases_refusal_of_an_overlap_into_slot_not_available(): void
    {
        $business = $this->sampleBusiness();
        $assessment = $this->service('Physio assessment');
        $before = app(AvailabilityService::class)->forService($business, $assessment, CarbonImmutable::parse('2026-11-09'), CarbonImmutable::parse('2026-11-09'));
        $this->book($business, $assessment, '2026-11-09 10:00');
        $this->mock(AvailabilityService::class, fn (MockInterface $mock) => $mock->shouldReceive('forService')->andReturn($before));

        $this->assertRefusedWithNothingWritten(fn () => $this->book($business, $assessment, '2026-11-09 10:00', [
            'name' => 'Jo Patel',
            'email' => 'jo@example.com',
            'phone' => '07700 900789',
        ]), expectedPrevious: '23P01');
    }

    /**
     * The sample business, made the current business. It's at strictness 0
     * unless a test gives another, since most of these tests are about what
     * fits and what's booked, not what strictness hides (07).
     */
    private function sampleBusiness(int $strictness = 0): Business
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00', 'Europe/London'));
        $business = app(CreateSampleBusiness::class)->handle();
        $business->update(['strictness' => $strictness]);
        app(CurrentBusiness::class)->set($business);

        return $business;
    }

    private function service(string $name): Service
    {
        return Service::query()->where('name', $name)->sole();
    }

    /**
     * @param  array{name: string, email: string, phone: string}  $customer
     */
    private function book(Business $business, Service $service, string $startsAt, array $customer = self::SAM, BookingSource $source = BookingSource::Admin): Booking
    {
        return app(CreateBooking::class)->handle($business, $service, CarbonImmutable::parse($startsAt, 'Europe/London'), $customer, $source);
    }

    /**
     * The booking is refused with SlotNotAvailable, and leaves the bookings
     * and customers as they were.
     *
     * @param  callable(): Booking  $booking
     */
    private function assertRefusedWithNothingWritten(callable $booking, ?string $expectedPrevious = null): void
    {
        $bookings = DB::table('bookings')->count();
        $customers = DB::table('customers')->count();

        try {
            $booking();
            $this->fail('The booking was accepted.');
        } catch (SlotNotAvailable $exception) {
            $this->assertSame($expectedPrevious, $exception->getPrevious()?->getCode());
        }

        $this->assertSame($bookings, DB::table('bookings')->count());
        $this->assertSame($customers, DB::table('customers')->count());
    }
}
