<?php

namespace Database\Factories;

use App\Models\Practitioner;
use App\Models\TimeOff;
use Database\Factories\Concerns\TakesBusinessFromPractitioner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Three hours off, starting on the hour a week from now, with no reason.
 *
 * @extends Factory<TimeOff>
 */
class TimeOffFactory extends Factory
{
    use TakesBusinessFromPractitioner;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = now()->addWeek()->startOfHour();

        return [
            'practitioner_id' => Practitioner::factory(),
            'business_id' => fn (array $attributes): int => $this->practitionersBusinessId($attributes),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHours(3),
            'reason' => null,
        ];
    }
}
