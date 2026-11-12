<?php

namespace App\Actions;

use App\Models\Business;
use App\Models\Practitioner;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Creates the sample business, a made-up independent physio and sports
 * massage therapist: local development's seed data, and each playground
 * visitor's sandbox (08).
 *
 * The data is fixed, with no Faker, because Faker is a development
 * dependency and the playground runs in production. Every business_id is
 * set through a relation rather than by the BelongsToBusiness hook, since
 * DatabaseSeeder runs with model events turned off. Dates are relative to
 * now in the business's time zone.
 */
class CreateSampleBusiness
{
    public const string TIMEZONE = 'Europe/London';

    private const array SPLIT_DAY = [['09:00', '13:00'], ['14:00', '17:30']];

    /**
     * The massage has a room weight of 5, as it's booked less often, so the
     * waste score doesn't keep as much room for it (06).
     *
     * @var list<array{name: string, duration_minutes: int, price_pence: int, room_weight: int}>
     */
    private const array SERVICES = [
        ['name' => 'Physio follow-up', 'duration_minutes' => 30, 'price_pence' => 5000, 'room_weight' => 10],
        ['name' => 'Physio assessment', 'duration_minutes' => 60, 'price_pence' => 7000, 'room_weight' => 10],
        ['name' => 'Sports massage, 90 minutes', 'duration_minutes' => 90, 'price_pence' => 7500, 'room_weight' => 5],
    ];

    public function handle(): Business
    {
        return DB::transaction(function (): Business {
            $business = Business::create([
                'name' => 'Alex Morgan Physio & Sports Massage',
                'timezone' => self::TIMEZONE,
            ]);
            $practitioner = $business->practitioners()->create(['name' => 'Alex Morgan']);

            foreach (self::SERVICES as $service) {
                $business->services()->create($service);
            }

            $this->addHours($business, $practitioner);

            return $business;
        });
    }

    /**
     * A fortnightly pattern from this week, with time off and an edited day
     * next week (week 2).
     */
    private function addHours(Business $business, Practitioner $practitioner): void
    {
        $thisMonday = now(self::TIMEZONE)->toImmutable()->startOfWeek(CarbonInterface::MONDAY);
        $nextTuesday = $thisMonday->addWeek()->addDay();
        $nextWednesday = $thisMonday->addWeek()->addDays(2);

        $practitioner->workingPatterns()->make([
            'starts_on' => $thisMonday->toDateString(),
            'ends_on' => null,
            'cycle_starts_on' => $thisMonday->toDateString(),
            'weeks' => [$this->weekOne(), $this->weekTwo()],
        ])->business()->associate($business)->save();

        $practitioner->timeOff()->make([
            'starts_at' => $nextTuesday->setTime(14, 0),
            'ends_at' => $nextTuesday->setTime(17, 30),
        ])->business()->associate($business)->save();

        $practitioner->editedDays()->make([
            'date' => $nextWednesday->toDateString(),
            'hours' => [['10:00', '16:00']],
        ])->business()->associate($business)->save();
    }

    /**
     * Monday to Friday, with an hour for lunch.
     *
     * @return array<string, list<array{string, string}>>
     */
    private function weekOne(): array
    {
        return [
            'mon' => self::SPLIT_DAY,
            'tue' => self::SPLIT_DAY,
            'wed' => self::SPLIT_DAY,
            'thu' => self::SPLIT_DAY,
            'fri' => self::SPLIT_DAY,
            'sat' => [],
            'sun' => [],
        ];
    }

    /**
     * Monday to Wednesday as week 1, a late Thursday, Friday off and a
     * Saturday morning.
     *
     * @return array<string, list<array{string, string}>>
     */
    private function weekTwo(): array
    {
        return [
            'mon' => self::SPLIT_DAY,
            'tue' => self::SPLIT_DAY,
            'wed' => self::SPLIT_DAY,
            'thu' => [['12:00', '20:00']],
            'fri' => [],
            'sat' => [['09:00', '13:00']],
            'sun' => [],
        ];
    }
}
