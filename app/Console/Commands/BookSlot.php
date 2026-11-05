<?php

namespace App\Console\Commands;

use App\Actions\CreateBooking;
use App\Console\Commands\Concerns\FindsBusinessAndService;
use App\Enums\BookingSource;
use App\Exceptions\ServiceNotBookable;
use App\Exceptions\SlotNotAvailable;
use App\Models\Business;
use App\Scheduling\LocalClock;
use App\Services\CurrentBusiness;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Books a time through CreateBooking, to try the engine around bookings of
 * your own before the playground: book a time, then run availability:show
 * for that date to see what changed. It's the counterpart of
 * availability:show.
 *
 * It refuses to run in production, since it writes bookings and nothing can
 * cancel one until 12 and 13. Start again with php artisan migrate:fresh
 * --seed to undo bookings made locally. It prints no customer details. The
 * default customer is made up: example.com is reserved for examples, and
 * Ofcom keeps 07700 900000 to 900999 for drama.
 */
#[Signature('booking:create
    {business : The business ID}
    {service : The service ID}
    {start : The start, as "YYYY-MM-DD HH:MM" in the business\'s time zone}
    {--name=Test Customer : The customer\'s name}
    {--email=test@example.com : The customer\'s email}
    {--phone=07700900000 : The customer\'s phone}')]
#[Description('Book a time through CreateBooking, to try the engine locally')]
class BookSlot extends Command
{
    use FindsBusinessAndService;

    public function handle(CurrentBusiness $currentBusiness, CreateBooking $createBooking): int
    {
        if ($this->laravel->isProduction()) {
            $this->error('booking:create writes bookings, and nothing can cancel one yet, so it doesn\'t run in production.');

            return self::FAILURE;
        }

        $business = $this->findBusiness();

        if ($business === null) {
            return self::FAILURE;
        }

        return $currentBusiness->run($business, fn (): int => $this->book($business, $createBooking));
    }

    private function book(Business $business, CreateBooking $createBooking): int
    {
        $service = $this->findService($business);

        if ($service === null) {
            return self::FAILURE;
        }

        $startsAt = $this->startFromArgument($business);
        $customer = $this->customerFromOptions();

        if ($startsAt === null || $customer === null) {
            return self::FAILURE;
        }

        try {
            $booking = $createBooking->handle($business, $service, $startsAt, $customer, BookingSource::Admin);
        } catch (ServiceNotBookable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (SlotNotAvailable) {
            $this->error(sprintf(
                '%s isn\'t one of the times offered for %s. Run php artisan availability:show %d %d --date=%s to see them.',
                $this->argument('start'),
                $service->name,
                $business->id,
                $service->id,
                $startsAt->setTimezone($business->timezone)->format('Y-m-d'),
            ));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Booked %s for %s, %s–%s (%s), booking %d.',
            $service->name,
            $booking->starts_at->setTimezone($business->timezone)->format('l j F Y'),
            $booking->starts_at->setTimezone($business->timezone)->format('H:i'),
            $booking->ends_at->setTimezone($business->timezone)->format('H:i'),
            $business->timezone,
            $booking->id,
        ));

        return self::SUCCESS;
    }

    /**
     * The start argument as an instant, through LocalClock, so it follows
     * the engine's rule on the nights the clocks change: a repeated time
     * means its first occurrence, where PHP's own parsing gives the second.
     * Null, after printing why, when it isn't a real date and time, or the
     * clocks skip it.
     */
    private function startFromArgument(Business $business): ?CarbonImmutable
    {
        $argument = $this->argument('start');
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $argument);

        if ($parsed === false || $parsed->format('Y-m-d H:i') !== $argument) {
            $this->error(sprintf('The start must be a real date and time written as "YYYY-MM-DD HH:MM", got "%s".', $argument));

            return null;
        }

        $date = CarbonImmutable::instance($parsed);
        $startsAt = (new LocalClock(new DateTimeZone($business->timezone)))->startTimeOn($date, $date->hour * 60 + $date->minute);

        if ($startsAt === null) {
            $this->error(sprintf('The clocks skip %s in %s, so it isn\'t a time there.', $argument, $business->timezone));
        }

        return $startsAt;
    }

    /**
     * The customer options, checked as every caller of CreateBooking must:
     * each is required and fits its column, and the email is a valid
     * address. Null, after printing the problems, when they aren't.
     *
     * @return array{name: string, email: string, phone: string}|null
     */
    private function customerFromOptions(): ?array
    {
        $customer = [
            'name' => $this->stringOption('name'),
            'email' => $this->stringOption('email'),
            'phone' => $this->stringOption('phone'),
        ];
        $validator = Validator::make($customer, [
            'name' => ['required', 'max:100'],
            'email' => ['required', 'email', 'max:254'],
            'phone' => ['required', 'max:30'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return null;
        }

        return $customer;
    }

    /**
     * An option's value, or an empty string, which the validation refuses,
     * when it has none.
     */
    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        return is_string($value) ? $value : '';
    }
}
