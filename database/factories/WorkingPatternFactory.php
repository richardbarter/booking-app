<?php

namespace Database\Factories;

use App\Models\Practitioner;
use App\Models\WorkingPattern;
use Carbon\CarbonInterface;
use Database\Factories\Concerns\TakesBusinessFromPractitioner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A weekly pattern, Monday to Friday 09:00–17:00, from the Monday of this
 * week with no end date.
 *
 * @extends Factory<WorkingPattern>
 */
class WorkingPatternFactory extends Factory
{
    use TakesBusinessFromPractitioner;

    private const array NINE_TO_FIVE = [['09:00', '17:00']];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $thisMonday = now()->startOfWeek(CarbonInterface::MONDAY)->toDateString();

        return [
            'practitioner_id' => Practitioner::factory(),
            'business_id' => fn (array $attributes): int => $this->practitionersBusinessId($attributes),
            'starts_on' => $thisMonday,
            'ends_on' => null,
            'cycle_starts_on' => $thisMonday,
            'weeks' => [self::week(['mon', 'tue', 'wed', 'thu', 'fri'])],
        ];
    }

    /**
     * A fortnightly pattern: Monday to Friday in week 1, and Monday to
     * Wednesday in week 2.
     */
    public function fortnightly(): static
    {
        return $this->state(fn (array $attributes) => [
            'weeks' => [
                self::week(['mon', 'tue', 'wed', 'thu', 'fri']),
                self::week(['mon', 'tue', 'wed']),
            ],
        ]);
    }

    /**
     * A pattern in force from the given date, with its cycle starting on the
     * Monday of that week.
     */
    public function startingOn(CarbonInterface $date): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_on' => $date->toDateString(),
            'cycle_starts_on' => $date->toImmutable()->startOfWeek(CarbonInterface::MONDAY)->toDateString(),
        ]);
    }

    /**
     * A pattern whose last date in force is the given date.
     */
    public function endingOn(CarbonInterface $date): static
    {
        return $this->state(fn (array $attributes) => [
            'ends_on' => $date->toDateString(),
        ]);
    }

    /**
     * A week in the stored form, working 09:00–17:00 on the given days.
     *
     * @param  list<string>  $workingDays
     * @return array<string, list<array{string, string}>>
     */
    private static function week(array $workingDays): array
    {
        $week = [];

        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $day) {
            $week[$day] = in_array($day, $workingDays, true) ? self::NINE_TO_FIVE : [];
        }

        return $week;
    }
}
