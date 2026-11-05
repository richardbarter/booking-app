<?php

namespace Tests\Unit\Scheduling\Hours;

use App\Scheduling\Hours\LocalTimeRange;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LocalTimeRangeTest extends TestCase
{
    public function test_accepts_the_whole_day_from_00_00_to_24_00(): void
    {
        $range = LocalTimeRange::fromStrings('00:00', '24:00');

        $this->assertSame(0, $range->startMinute);
        $this->assertSame(1440, $range->endMinute);
        $this->assertSame(['00:00', '24:00'], $range->toArray());
    }

    public function test_holds_times_as_minutes_after_midnight(): void
    {
        $range = LocalTimeRange::fromStrings('09:05', '17:30');

        $this->assertSame(545, $range->startMinute);
        $this->assertSame(1050, $range->endMinute);
        $this->assertSame(['09:05', '17:30'], $range->toArray());
    }

    public function test_writes_times_late_in_an_hour_back_in_the_same_form(): void
    {
        $range = LocalTimeRange::fromStrings('11:55', '19:45');

        $this->assertSame(['11:55', '19:45'], $range->toArray());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function rangesThatDoNotStartBeforeTheyEnd(): array
    {
        return [
            'start equal to end' => ['09:00', '09:00'],
            'start after end' => ['13:00', '09:00'],
        ];
    }

    #[DataProvider('rangesThatDoNotStartBeforeTheyEnd')]
    public function test_rejects_a_start_at_or_after_its_end(string $start, string $end): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must start before it ends');

        LocalTimeRange::fromStrings($start, $end);
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function minutesOutsideTheDay(): array
    {
        return [
            'start before midnight' => [-5, 60],
            'end after 24:00' => [0, 1445],
        ];
    }

    #[DataProvider('minutesOutsideTheDay')]
    public function test_rejects_times_outside_the_day(int $startMinute, int $endMinute): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be from 00:00 to 24:00');

        new LocalTimeRange($startMinute, $endMinute);
    }

    public function test_rejects_a_time_written_after_24_00(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be from 00:00 to 24:00');

        LocalTimeRange::fromStrings('09:00', '24:30');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function timesOffTheStep(): array
    {
        return [
            'start' => ['09:03', '13:00'],
            'end' => ['09:00', '12:59'],
        ];
    }

    #[DataProvider('timesOffTheStep')]
    public function test_rejects_times_not_on_a_5_minute_step(string $start, string $end): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be on a 5-minute step');

        LocalTimeRange::fromStrings($start, $end);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badlyFormedTimes(): array
    {
        return [
            'one-digit hour' => ['9:00'],
            'minutes over 59' => ['09:60'],
            'no colon' => ['0900'],
            'with seconds' => ['09:00:00'],
            'empty' => [''],
            'leading space' => [' 09:00'],
            'trailing newline' => ["09:00\n"],
            'letters' => ['ab:cd'],
        ];
    }

    #[DataProvider('badlyFormedTimes')]
    public function test_rejects_badly_formed_strings(string $time): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be written as HH:MM');

        LocalTimeRange::fromStrings($time, '23:00');
    }
}
