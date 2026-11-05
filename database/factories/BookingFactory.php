<?php

namespace Database\Factories;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Practitioner;
use App\Models\Scopes\BusinessScope;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\Concerns\TakesBusinessFromPractitioner;
use Illuminate\Database\Eloquent\Factories\Factory;
use LogicException;

/**
 * A confirmed booking at 10:00 on the next weekday, in the business's time
 * zone. The service and customer belong to the practitioner's business, and
 * the times, price and details as given come from them, as CreateBooking
 * saves them. Tests that create more than one booking choose times that don't
 * overlap, or the exclusion constraint refuses them.
 *
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    use TakesBusinessFromPractitioner;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'practitioner_id' => Practitioner::factory(),
            'business_id' => fn (array $attributes): int => $this->practitionersBusinessId($attributes),
            'service_id' => fn (array $attributes): ServiceFactory => Service::factory()->state(['business_id' => $attributes['business_id']]),
            'customer_id' => fn (array $attributes): CustomerFactory => Customer::factory()->state(['business_id' => $attributes['business_id']]),
            'starts_at' => fn (array $attributes): CarbonImmutable => $this->nextWeekdayAtTen($attributes),
            'ends_at' => fn (array $attributes): CarbonImmutable => $this->instant($attributes, 'starts_at')
                ->addMinutes($this->service($attributes)->duration_minutes),
            'blocked_from' => fn (array $attributes): CarbonImmutable => $this->instant($attributes, 'starts_at')
                ->subMinutes($this->service($attributes)->buffer_before_minutes),
            'blocked_until' => fn (array $attributes): CarbonImmutable => $this->instant($attributes, 'ends_at')
                ->addMinutes($this->service($attributes)->buffer_after_minutes),
            'customer_name' => fn (array $attributes): string => $this->customer($attributes)->name,
            'customer_phone' => fn (array $attributes): string => $this->customer($attributes)->phone,
            'price_pence' => fn (array $attributes): int => $this->service($attributes)->price_pence,
            'status' => BookingStatus::Confirmed,
            'source' => BookingSource::Admin,
        ];
    }

    /**
     * A booking starting at the given moment, with its other times worked
     * out from the service.
     */
    public function at(CarbonInterface $startsAt): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => $startsAt,
        ]);
    }

    /**
     * A cancelled booking, which doesn't block its time.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BookingStatus::Cancelled,
        ]);
    }

    /**
     * A booking the owner marked as a no-show, which still blocks its time.
     */
    public function noShow(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BookingStatus::NoShow,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function nextWeekdayAtTen(array $attributes): CarbonImmutable
    {
        $businessId = $attributes['business_id'] ?? null;

        if (! is_int($businessId)) {
            throw new LogicException('A business is needed to know which time zone the booking is in.');
        }

        $timezone = Business::query()->findOrFail($businessId)->timezone;

        return CarbonImmutable::now($timezone)->addWeekday()->setTime(10, 0);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function instant(array $attributes, string $key): CarbonImmutable
    {
        $value = $attributes[$key] ?? null;

        if (! $value instanceof CarbonInterface) {
            throw new LogicException(sprintf('The booking\'s %s must be a date and time.', $key));
        }

        return $value->toImmutable();
    }

    /**
     * Read without the tenant scope, as the practitioner is, since tests
     * often create data for several businesses with no current business.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function service(array $attributes): Service
    {
        $serviceId = $attributes['service_id'] ?? null;

        if (! is_int($serviceId)) {
            throw new LogicException('A service is needed to work out the booking\'s times and price.');
        }

        return Service::withoutGlobalScope(BusinessScope::class)->findOrFail($serviceId);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function customer(array $attributes): Customer
    {
        $customerId = $attributes['customer_id'] ?? null;

        if (! is_int($customerId)) {
            throw new LogicException('A customer is needed to fill in the details given with the booking.');
        }

        return Customer::withoutGlobalScope(BusinessScope::class)->findOrFail($customerId);
    }
}
