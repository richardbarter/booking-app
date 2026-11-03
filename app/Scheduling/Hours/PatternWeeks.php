<?php

namespace App\Scheduling\Hours;

use Countable;
use InvalidArgumentException;

/**
 * The weeks of a working pattern's cycle: 1 for weekly, 2 for fortnightly,
 * and up to 4.
 */
final readonly class PatternWeeks implements Countable
{
    public const int MAX_WEEKS = 4;

    /**
     * @var list<WeekHours>
     */
    private array $weeks;

    /**
     * @param  list<WeekHours>  $weeks  week 1 first
     */
    public function __construct(array $weeks)
    {
        if ($weeks === [] || count($weeks) > self::MAX_WEEKS) {
            throw new InvalidArgumentException(sprintf(
                'A pattern must have 1 to %d weeks, got %d.',
                self::MAX_WEEKS,
                count($weeks),
            ));
        }

        $this->weeks = $weeks;
    }

    /**
     * Build the weeks from their stored form: a list with one week per item.
     *
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        if (! array_is_list($data)) {
            throw new InvalidArgumentException('A pattern\'s weeks must be a list.');
        }

        $weeks = [];

        foreach ($data as $week) {
            if (! is_array($week)) {
                throw new InvalidArgumentException('Each week of a pattern must have the keys mon to sun.');
            }

            $weeks[] = WeekHours::fromArray($week);
        }

        return new self($weeks);
    }

    public function count(): int
    {
        return count($this->weeks);
    }

    /**
     * The week at a zero-based index in the cycle, so week 1 is index 0.
     */
    public function week(int $index): WeekHours
    {
        if (! array_key_exists($index, $this->weeks)) {
            throw new InvalidArgumentException(sprintf(
                'A %d-week cycle has no week at index %d.',
                count($this->weeks),
                $index,
            ));
        }

        return $this->weeks[$index];
    }

    /**
     * @return list<array<string, list<array{string, string}>>>
     */
    public function toArray(): array
    {
        return array_map(fn (WeekHours $week): array => $week->toArray(), $this->weeks);
    }
}
