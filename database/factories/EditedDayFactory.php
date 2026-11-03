<?php

namespace Database\Factories;

use App\Models\EditedDay;
use App\Models\Practitioner;
use Database\Factories\Concerns\TakesBusinessFromPractitioner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A day within the next year, worked from 10:00 to 16:00. Each date is
 * different, so a practitioner can have several.
 *
 * @extends Factory<EditedDay>
 */
class EditedDayFactory extends Factory
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
            'date' => now()->addDays(fake()->unique()->numberBetween(1, 366))->toDateString(),
            'hours' => [['10:00', '16:00']],
        ];
    }

    /**
     * A day the practitioner isn't working.
     */
    public function dayOff(): static
    {
        return $this->state(fn (array $attributes) => [
            'hours' => [],
        ]);
    }
}
