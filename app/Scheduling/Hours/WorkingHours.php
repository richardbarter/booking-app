<?php

namespace App\Scheduling\Hours;

use Carbon\CarbonInterface;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One practitioner's working hours on any date: an edited day's hours if
 * there is one, otherwise the hours of the pattern in force, otherwise none.
 */
final readonly class WorkingHours
{
    /**
     * @var list<RepeatingPattern>
     */
    private array $patterns;

    /**
     * @param  list<RepeatingPattern>  $patterns  which mustn't overlap
     * @param  array<string, DayHours>  $editedDays  keyed by Y-m-d
     */
    public function __construct(array $patterns, private array $editedDays)
    {
        usort($patterns, fn (RepeatingPattern $first, RepeatingPattern $second): int => $first->startsOn <=> $second->startsOn);

        for ($index = 1; $index < count($patterns); $index++) {
            $previous = $patterns[$index - 1];
            $current = $patterns[$index];

            if ($previous->endsOn === null || $previous->endsOn >= $current->startsOn) {
                throw new InvalidArgumentException(sprintf(
                    'Working patterns can\'t overlap, got one from %s and one from %s.',
                    $previous->startsOn,
                    $current->startsOn,
                ));
            }
        }

        foreach (array_keys($editedDays) as $date) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

            if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                throw new InvalidArgumentException(sprintf('Edited days must be keyed by Y-m-d dates, got "%s".', $date));
            }
        }

        $this->patterns = $patterns;
    }

    public function on(CarbonInterface $date): DayHours
    {
        $editedDay = $this->editedDays[$date->format('Y-m-d')] ?? null;

        if ($editedDay !== null) {
            return $editedDay;
        }

        foreach ($this->patterns as $pattern) {
            if ($pattern->covers($date)) {
                return $pattern->hoursOn($date);
            }
        }

        return new DayHours;
    }
}
