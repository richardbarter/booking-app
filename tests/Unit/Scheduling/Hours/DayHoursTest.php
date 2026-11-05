<?php

namespace Tests\Unit\Scheduling\Hours;

use App\Scheduling\Hours\DayHours;
use App\Scheduling\Hours\LocalTimeRange;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DayHoursTest extends TestCase
{
    public function test_keeps_its_ranges_in_time_order(): void
    {
        $day = new DayHours([
            LocalTimeRange::fromStrings('14:00', '17:30'),
            LocalTimeRange::fromStrings('09:00', '13:00'),
        ]);

        $this->assertSame([['09:00', '13:00'], ['14:00', '17:30']], $day->toArray());
        $this->assertSame(540, $day->ranges()[0]->startMinute);
    }

    /**
     * @return array<string, array{list<array{string, string}>}>
     */
    public static function rangesThatOverlapOrTouch(): array
    {
        return [
            'overlapping' => [[['09:00', '13:00'], ['12:00', '17:00']]],
            'touching' => [[['09:00', '13:00'], ['13:00', '17:00']]],
            'touching, given in reverse order' => [[['13:00', '17:00'], ['09:00', '13:00']]],
            'the same start' => [[['09:00', '10:00'], ['09:00', '13:00']]],
            'one inside another' => [[['09:00', '17:00'], ['10:00', '11:00']]],
        ];
    }

    /**
     * @param  list<array{string, string}>  $ranges
     */
    #[DataProvider('rangesThatOverlapOrTouch')]
    public function test_rejects_ranges_that_overlap_or_touch(array $ranges): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('can\'t overlap or touch');

        DayHours::fromArray($ranges);
    }

    public function test_allows_a_five_minute_break(): void
    {
        $day = DayHours::fromArray([['09:00', '13:00'], ['13:05', '17:00']]);

        $this->assertCount(2, $day->ranges());
    }

    public function test_an_empty_day_has_no_hours(): void
    {
        $day = DayHours::fromArray([]);

        $this->assertTrue($day->isEmpty());
        $this->assertSame([], $day->ranges());
        $this->assertSame([], $day->toArray());
    }

    public function test_a_day_with_a_range_is_not_empty(): void
    {
        $day = DayHours::fromArray([['10:00', '16:00']]);

        $this->assertFalse($day->isEmpty());
    }

    public function test_from_array_gives_back_what_to_array_wrote(): void
    {
        $stored = [['00:00', '06:00'], ['09:00', '13:00'], ['14:00', '24:00']];

        $this->assertSame($stored, DayHours::fromArray(DayHours::fromArray($stored)->toArray())->toArray());
    }

    /**
     * @return array<string, array{array<mixed>}>
     */
    public static function badlyShapedDays(): array
    {
        return [
            'keyed ranges' => [['morning' => ['09:00', '13:00']]],
            'a range that is a string' => [['09:00–13:00']],
            'a range with one time' => [[['09:00']]],
            'a range with three times' => [[['09:00', '13:00', '17:00']]],
            'times that are numbers' => [[[900, 1300]]],
            'a range with keys' => [[['start' => '09:00', 'end' => '13:00']]],
        ];
    }

    /**
     * @param  array<mixed>  $data
     */
    #[DataProvider('badlyShapedDays')]
    public function test_from_array_rejects_badly_shaped_hours(array $data): void
    {
        $this->expectException(InvalidArgumentException::class);

        DayHours::fromArray($data);
    }

    /**
     * @return array<string, array{array<mixed>}>
     */
    public static function rangesWithATimeThatIsNotAString(): array
    {
        return [
            'both' => [[[900, 1300]]],
            'the start' => [[[900, '13:00']]],
            'the end' => [[['09:00', 1300]]],
        ];
    }

    /**
     * @param  array<mixed>  $data
     */
    #[DataProvider('rangesWithATimeThatIsNotAString')]
    public function test_from_array_says_each_range_must_be_a_pair_of_times(array $data): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Each time range must be a pair of times');

        DayHours::fromArray($data);
    }
}
