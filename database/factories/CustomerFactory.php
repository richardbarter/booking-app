<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A made-up customer, with an email at a domain reserved for examples,
 * stored in lower case as the customers table requires.
 *
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
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
            'email' => Str::lower(fake()->unique()->safeEmail()),
            'phone' => fake()->phoneNumber(),
        ];
    }
}
