<?php

namespace Tests\Unit\Scheduling\Hours;

use App\Scheduling\Hours\PatternWeeks;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PatternWeeksTest extends TestCase
{
    /**
     * @return array<string, array{int}>
     */
    public static function allowedWeekCounts(): array
    {
        return [
            'weekly' => [1],
            'fortnightly' => [2],
            '3-weekly' => [3],
            '4-weekly' => [4],
        ];
    }

    #[DataProvider('allowedWeekCounts')]
    public function test_accepts_1_to_4_weeks(int $weekCount): void
    {
        $weeks = PatternWeeks::fromArray($this->weeksStartingAt(1, $weekCount));

        $this->assertSame($weekCount, $weeks->count());
    }

    /**
     * @return array<string, array{int}>
     */
    public static function disallowedWeekCounts(): array
    {
        return [
            'no weeks' => [0],
            '5 weeks' => [5],
        ];
    }

    #[DataProvider('disallowedWeekCounts')]
    public function test_rejects_0_or_5_weeks(int $weekCount): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("must have 1 to 4 weeks, got {$weekCount}");

        PatternWeeks::fromArray($this->weeksStartingAt(1, $weekCount));
    }

    public function test_week_gives_the_week_at_a_zero_based_index(): void
    {
        $weeks = PatternWeeks::fromArray($this->weeksStartingAt(1, 3));

        $this->assertSame([['01:00', '02:00']], $weeks->week(0)->forWeekday(1)->toArray());
        $this->assertSame([['02:00', '03:00']], $weeks->week(1)->forWeekday(1)->toArray());
        $this->assertSame([['03:00', '04:00']], $weeks->week(2)->forWeekday(1)->toArray());
    }

    /**
     * @return array<string, array{int}>
     */
    public static function indexesOutsideAThreeWeekCycle(): array
    {
        return [
            'negative' => [-1],
            'one past the last week' => [3],
        ];
    }

    #[DataProvider('indexesOutsideAThreeWeekCycle')]
    public function test_week_rejects_an_index_outside_the_cycle(int $index): void
    {
        $weeks = PatternWeeks::fromArray($this->weeksStartingAt(1, 3));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("has no week at index {$index}");

        $weeks->week($index);
    }

    public function test_from_array_gives_back_what_to_array_wrote(): void
    {
        $stored = $this->weeksStartingAt(1, 2);

        $this->assertSame($stored, PatternWeeks::fromArray($stored)->toArray());
    }

    public function test_rejects_weeks_that_are_not_a_list(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a list');

        PatternWeeks::fromArray(['week 1' => $this->weeksStartingAt(1, 1)[0]]);
    }

    public function test_rejects_a_week_that_is_not_a_set_of_days(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Each week of a pattern');

        PatternWeeks::fromArray(['every weekday']);
    }

    /**
     * Weeks whose Mondays differ, so each week can be told apart: the first
     * works from 01:00 to 02:00, the next from 02:00 to 03:00, and so on.
     *
     * @return list<array<string, list<array{string, string}>>>
     */
    private function weeksStartingAt(int $firstHour, int $weekCount): array
    {
        $weeks = [];

        for ($week = 0; $week < $weekCount; $week++) {
            $hour = $firstHour + $week;
            $weeks[] = [
                'mon' => [[sprintf('%02d:00', $hour), sprintf('%02d:00', $hour + 1)]],
                'tue' => [], 'wed' => [], 'thu' => [], 'fri' => [], 'sat' => [], 'sun' => [],
            ];
        }

        return $weeks;
    }
}
