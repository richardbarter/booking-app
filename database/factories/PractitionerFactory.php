<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Practitioner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A practitioner with a business of its own, so a test can't give a business
 * a second practitioner by accident.
 *
 * @extends Factory<Practitioner>
 */
class PractitionerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'name' => fake()->name(),
        ];
    }
}
