<?php

namespace App\Actions;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Exceptions\MissingCurrentBusiness;
use App\Exceptions\ServiceNotBookable;
use App\Exceptions\SlotNotAvailable;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Practitioner;
use App\Models\Service;
use App\Scheduling\Slot;
use App\Services\AvailabilityService;
use App\Services\CurrentBusiness;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * Books a start time for a customer, so a practitioner can never be double
 * booked, even when two customers book the same time at once.
 *
 * Inside one transaction it locks the practitioner's row, so bookings for
 * one diary take turns, then checks again that the start is one of the
 * offered slots and saves the booking with that slot's times. The database's
 * exclusion constraint catches anything that gets past the check, and its
 * refusal becomes SlotNotAvailable too. A refused booking writes nothing,
 * not even a new customer.
 *
 * Callers validate the customer's details first (11's and 13's Form
 * Requests, and booking:create).
 */
class CreateBooking
{
    /**
     * PostgreSQL's SQLSTATE for an exclusion constraint violation, which is
     * how bookings_no_overlap refuses an overlap.
     */
    private const string EXCLUSION_VIOLATION = '23P01';

    public function __construct(
        private CurrentBusiness $currentBusiness,
        private AvailabilityService $availability,
    ) {}

    /**
     * @param  array{name: string, email: string, phone: string}  $customer  as given
     *
     * @throws MissingCurrentBusiness when no current business is set
     * @throws LogicException when the current business is another one
     * @throws InvalidArgumentException when the service is another business's
     * @throws ServiceNotBookable when the service isn't active
     * @throws SlotNotAvailable when the start isn't one of the times offered
     */
    public function handle(
        Business $business,
        Service $service,
        CarbonInterface $startsAt,
        array $customer,
        BookingSource $source,
    ): Booking {
        $this->currentBusiness->ensureIs($business);

        return DB::transaction(function () use ($business, $service, $startsAt, $customer, $source): Booking {
            $practitioner = $business->practitioners()->lockForUpdate()->sole();
            $slot = $this->offeredSlot($business, $service, $startsAt);
            $details = $this->normalised($customer);

            return $this->save(
                $this->booking($slot, $service, $details, $source),
                $business,
                $practitioner,
                $service,
                $this->findOrCreateCustomer($business, $details),
            );
        });
    }

    /**
     * The slot starting at the same instant as $startsAt, whatever zone the
     * caller gave it in. forService() reads only each date's calendar date
     * as it's given, so the start is turned into the business's time zone
     * first. A start with seconds is never on the slot interval.
     *
     * @throws SlotNotAvailable
     */
    private function offeredSlot(Business $business, Service $service, CarbonInterface $startsAt): Slot
    {
        $localStart = $startsAt->toImmutable()->setTimezone($business->timezone);
        $day = $this->availability->forService($business, $service, $localStart, $localStart)->day($localStart->format('Y-m-d'));

        foreach ($day->slots ?? [] as $slot) {
            if ($slot->startsAt->equalTo($startsAt)) {
                return $slot;
            }
        }

        throw new SlotNotAvailable($service->id, $startsAt);
    }

    /**
     * Each detail trimmed, and the email in lower case, so customers are
     * matched however the email was typed.
     *
     * @param  array{name: string, email: string, phone: string}  $customer
     * @return array{name: string, email: string, phone: string}
     */
    private function normalised(array $customer): array
    {
        return [
            'name' => trim($customer['name']),
            'email' => Str::lower(trim($customer['email'])),
            'phone' => trim($customer['phone']),
        ];
    }

    /**
     * An existing customer's details are never changed, so knowing someone's
     * email isn't enough to change what the owner sees. The relation scopes
     * the lookup to the business and sets business_id, which isn't fillable.
     *
     * @param  array{name: string, email: string, phone: string}  $details
     */
    private function findOrCreateCustomer(Business $business, array $details): Customer
    {
        return $business->customers()->createOrFirst(
            ['email' => $details['email']],
            ['name' => $details['name'], 'phone' => $details['phone']],
        );
    }

    /**
     * The times come from the slot and the price from the service, so
     * changing the service later never moves the booking or changes its
     * price.
     *
     * @param  array{name: string, email: string, phone: string}  $details
     */
    private function booking(Slot $slot, Service $service, array $details, BookingSource $source): Booking
    {
        return new Booking([
            'starts_at' => $slot->startsAt,
            'ends_at' => $slot->endsAt,
            'blocked_from' => $slot->blockedFrom,
            'blocked_until' => $slot->blockedUntil,
            'customer_name' => $details['name'],
            'customer_phone' => $details['phone'],
            'price_pence' => $service->price_pence,
            'status' => BookingStatus::Confirmed,
            'source' => $source,
        ]);
    }

    /**
     * The foreign keys are set through relations, as the customer's is, so
     * saving works with model events off, as in DatabaseSeeder.
     *
     * @throws SlotNotAvailable when the database refuses an overlap
     */
    private function save(Booking $booking, Business $business, Practitioner $practitioner, Service $service, Customer $customer): Booking
    {
        $booking->business()->associate($business);
        $booking->practitioner()->associate($practitioner);
        $booking->service()->associate($service);
        $booking->customer()->associate($customer);

        try {
            $booking->save();
        } catch (QueryException $exception) {
            if ($exception->getCode() === self::EXCLUSION_VIOLATION) {
                throw new SlotNotAvailable($service->id, $booking->starts_at, $exception);
            }

            throw $exception;
        }

        return $booking;
    }
}
