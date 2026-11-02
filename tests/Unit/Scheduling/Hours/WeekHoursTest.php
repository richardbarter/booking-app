<?php

namespace Tests\Unit\Scheduling\Hours;

use App\Scheduling\Hours\WeekHours;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WeekHoursTest extends TestCase
{
    public function test_gives_each_iso_weekday_its_own_hours(): void
    {
        $week = WeekHours::fromArray($this->distinctWeek());

        $this->assertSame([['01:00', '02:00']], $week->forWeekday(1)->toArray());
        $this->assertSame([['02:00', '03:00']], $week->forWeekday(2)->toArray());
        $this->assertSame([['03:00', '04:00']], $week->forWeekday(3)->toArray());
        $this->assertSame([['04:00', '05:00']], $week->forWeekday(4)->toArray());
        $this->assertSame([['05:00', '06:00']], $week->forWeekday(5)->toArray());
        $this->assertSame([['06:00', '07:00']], $week->forWeekday(6)->toArray());
        $this->assertSame([], $week->forWeekday(7)->toArray());
    }

    public function test_from_array_gives_back_what_to_array_wrote(): void
    {
        $stored = $this->distinctWeek();

        $this->assertSame($stored, WeekHours::fromArray($stored)->toArray());
    }

    public function test_reads_the_days_whatever_order_they_are_stored_in(): void
    {
        $stored = array_reverse($this->distinctWeek(), preserve_keys: true);

        $this->assertSame([['01:00', '02:00']], WeekHours::fromArray($stored)->forWeekday(1)->toArray());
    }

    /**
     * @return array<string, array{int}>
     */
    public static function weekdaysOutsideOneToSeven(): array
    {
        return [
            'zero' => [0],
            'eight' => [8],
        ];
    }

    #[DataProvider('weekdaysOutsideOneToSeven')]
    public function test_rejects_a_weekday_outside_1_to_7(int $isoWeekday): void
    {
        $week = WeekHours::fromArray($this->distinctWeek());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('from 1 (Monday) to 7 (Sunday)');

        $week->forWeekday($isoWeekday);
    }

    public function test_rejects_a_week_without_all_seven_days(): void
    {
        $stored = $this->distinctWeek();
        unset($stored['sun']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exactly the keys mon, tue, wed, thu, fri, sat, sun');

        WeekHours::fromArray($stored);
    }

    public function test_rejects_a_week_with_an_extra_day(): void
    {
        $stored = $this->distinctWeek() + ['holiday' => []];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exactly the keys');

        WeekHours::fromArray($stored);
    }

    public function test_rejects_a_week_listed_without_day_keys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exactly the keys');

        WeekHours::fromArray([[], [], [], [], [], [], []]);
    }

    public function test_rejects_a_day_that_is_not_a_list_of_ranges(): void
    {
        $stored = $this->distinctWeek();
        $stored['wed'] = '09:00–17:00';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The hours for "wed"');

        WeekHours::fromArray($stored);
    }

    /**
     * A week whose days each have different hours, and Sunday none.
     *
     * @return array<string, list<array{string, string}>>
     */
    private function distinctWeek(): array
    {
        return [
            'mon' => [['01:00', '02:00']],
            'tue' => [['02:00', '03:00']],
            'wed' => [['03:00', '04:00']],
            'thu' => [['04:00', '05:00']],
            'fri' => [['05:00', '06:00']],
            'sat' => [['06:00', '07:00']],
            'sun' => [],
        ];
    }
}
