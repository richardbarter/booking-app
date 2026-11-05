<?php

namespace Database\Seeders;

use App\Actions\CreateBooking;
use App\Actions\CreateSampleBusiness;
use App\Enums\BookingSource;
use App\Models\Business;
use App\Models\User;
use App\Services\CurrentBusiness;
use Carbon\CarbonInterface;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Three bookings next Monday, so availability:show shows the engine
     * working around real bookings. The customers are made up, at a domain
     * reserved for examples and Ofcom's drama numbers.
     *
     * @var list<array{service: string, time: string, customer: array{name: string, email: string, phone: string}}>
     */
    private const array SAMPLE_BOOKINGS = [
        ['service' => 'Physio assessment', 'time' => '09:30', 'customer' => ['name' => 'Jordan Lee', 'email' => 'jordan.lee@example.com', 'phone' => '07700 900101']],
        ['service' => 'Physio follow-up', 'time' => '11:00', 'customer' => ['name' => 'Priya Shah', 'email' => 'priya.shah@example.com', 'phone' => '07700 900102']],
        ['service' => 'Sports massage, 90 minutes', 'time' => '14:30', 'customer' => ['name' => 'Morgan Price', 'email' => 'morgan.price@example.com', 'phone' => '07700 900103']],
    ];

    /**
     * Seed the application's database.
     */
    public function run(CreateSampleBusiness $createSampleBusiness, CreateBooking $createBooking, CurrentBusiness $currentBusiness): void
    {
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $business = $createSampleBusiness->handle();

        $currentBusiness->run($business, fn () => $this->bookSamples($business, $createBooking));
    }

    /**
     * Through CreateBooking, as a customer would book them, so they follow
     * the same rules.
     */
    private function bookSamples(Business $business, CreateBooking $createBooking): void
    {
        $nextMonday = now($business->timezone)->toImmutable()->startOfWeek(CarbonInterface::MONDAY)->addWeek();

        foreach (self::SAMPLE_BOOKINGS as $sample) {
            $createBooking->handle(
                $business,
                $business->services()->where('name', $sample['service'])->sole(),
                $nextMonday->setTimeFromTimeString($sample['time']),
                $sample['customer'],
                BookingSource::Admin,
            );
        }
    }
}
