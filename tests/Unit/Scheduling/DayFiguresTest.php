<?php

namespace Tests\Unit\Scheduling;

use App\Scheduling\BookingSettings;
use App\Scheduling\DateRange;
use App\Scheduling\DayAvailability;
use App\Scheduling\DayFigures;
use App\Scheduling\Diary;
use App\Scheduling\GapSummary;
use App\Scheduling\Hours\DayHours;
use App\Scheduling\Hours\WorkingHours;
use App\Scheduling\OfferedService;
use App\Scheduling\ServiceTimes;
use App\Scheduling\SlotFinder;
use App\Scheduling\TimeRange;
use Carbon\CarbonImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Days are Monday 9 November 2026, worked from 09:00 to 13:00 and 14:00 to
 * 17:30, with the sample business's services. Times are in London, which is
 * on UTC.
 */
class DayFiguresTest extends TestCase
{
    private const string MONDAY = '2026-11-09';

    /**
     * The morning's bookings leave 09:00–09:15 stranded and 11:30–13:00
     * usable. Time off from 15:00 leaves 14:00–15:00, which the 90-minute
     * massage doesn't fit.
     */
    public function test_adds_up_a_day_with_bookings_a_stranded_gap_and_time_off(): void
    {
        $appointments = [$this->range('09:15', '10:15'), $this->range('10:15', '11:30')];

        $figures = DayFigures::of($this->day([...$appointments, $this->range('15:00', '17:30')]), $appointments);

        $this->assertSame([450, 135, 165, 15, 60], $this->numbers($figures));
    }

    public function test_leaves_buffers_out_of_booked_time_and_out_of_free_time(): void
    {
        $blocked = $this->range('09:00', '10:30');
        $appointment = $this->range('09:15', '10:15');

        $figures = DayFigures::of($this->day([$blocked]), [$appointment]);

        $this->assertSame([450, 60, 360, 0, 0], $this->numbers($figures));
    }

    public function test_an_empty_day_is_all_free(): void
    {
        $figures = DayFigures::of($this->day([]), []);

        $this->assertSame([450, 0, 450, 0, 0], $this->numbers($figures));
        $this->assertNull($figures->efficiency());
    }

    public function test_a_day_off_has_no_minutes_and_no_efficiency(): void
    {
        $figures = DayFigures::of(new DayAvailability(self::MONDAY, [], [], [], [], new GapSummary), []);

        $this->assertSame([0, 0, 0, 0, 0], $this->numbers($figures));
        $this->assertNull($figures->efficiency());
    }

    /**
     * @return array<string, array{int, int, int}>
     */
    public static function bookedStrandedAndEfficiency(): array
    {
        return [
            'nothing stranded' => [60, 0, 100],
            'everything stranded' => [0, 15, 0],
            'an exact percentage' => [60, 15, 80],
            'exactly a half, 12.5' => [25, 175, 13],
            'just below a half, 33.3' => [10, 20, 33],
            'just above a half, 66.7' => [20, 10, 67],
            'one minute booked and nothing stranded' => [1, 0, 100],
        ];
    }

    #[DataProvider('bookedStrandedAndEfficiency')]
    public function test_gives_the_percentage_of_the_used_time_that_was_booked(int $booked, int $stranded, int $efficiency): void
    {
        $figures = new DayFigures(450, $booked, 200, $stranded, 30);

        $this->assertSame($efficiency, $figures->efficiency());
    }

    /**
     * @param  list<TimeRange>  $blockedTimes
     */
    private function day(array $blockedTimes): DayAvailability
    {
        $availability = (new SlotFinder)->find(
            new Diary(
                new WorkingHours([], [self::MONDAY => DayHours::fromArray([['09:00', '13:00'], ['14:00', '17:30']])]),
                [new OfferedService(new ServiceTimes(30)), new OfferedService(new ServiceTimes(60)), new OfferedService(new ServiceTimes(90), 5)],
                $blockedTimes,
            ),
            new ServiceTimes(30),
            new BookingSettings(new DateTimeZone('Europe/London'), 15, 120, 90, strictness: 0, relaxationHours: 48),
            DateRange::between(CarbonImmutable::parse(self::MONDAY), CarbonImmutable::parse(self::MONDAY)),
            CarbonImmutable::parse('2026-11-02 08:00', 'Europe/London'),
        );

        $day = $availability->day(self::MONDAY);
        $this->assertNotNull($day);

        return $day;
    }

    private function range(string $start, string $end): TimeRange
    {
        return new TimeRange(
            CarbonImmutable::parse(self::MONDAY." {$start}", 'Europe/London'),
            CarbonImmutable::parse(self::MONDAY." {$end}", 'Europe/London'),
        );
    }

    /**
     * Working, booked, free, stranded and fragmented minutes.
     *
     * @return array{int, int, int, int, int}
     */
    private function numbers(DayFigures $figures): array
    {
        return [$figures->workingMinutes, $figures->bookedMinutes, $figures->freeMinutes, $figures->strandedMinutes, $figures->fragmentedMinutes];
    }
}
