<?php

namespace Tests\Unit\Scheduling;

use App\Scheduling\BookingSettings;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BookingSettingsTest extends TestCase
{
    /**
     * @return array<string, array{int}>
     */
    public static function slotIntervals(): array
    {
        return ['10' => [10], '15' => [15], '20' => [20], '30' => [30], '60' => [60]];
    }

    #[DataProvider('slotIntervals')]
    public function test_accepts_each_slot_interval(int $minutes): void
    {
        $settings = new BookingSettings(new DateTimeZone('Europe/London'), $minutes, 120, 90);

        $this->assertSame($minutes, $settings->slotIntervalMinutes);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function otherIntervals(): array
    {
        return ['0' => [0], '5' => [5], '45' => [45], '120' => [120]];
    }

    #[DataProvider('otherIntervals')]
    public function test_rejects_any_other_slot_interval(int $minutes): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be 10, 15, 20, 30, 60 minutes');

        new BookingSettings(new DateTimeZone('Europe/London'), $minutes, 120, 90);
    }

    public function test_keeps_no_notice_and_a_one_day_advance(): void
    {
        $settings = new BookingSettings(new DateTimeZone('Asia/Kathmandu'), 15, 0, 1);

        $this->assertSame('Asia/Kathmandu', $settings->timezone->getName());
        $this->assertSame(0, $settings->minNoticeMinutes);
        $this->assertSame(1, $settings->maxAdvanceDays);
    }

    public function test_rejects_negative_notice(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('minimum notice can\'t be negative');

        new BookingSettings(new DateTimeZone('Europe/London'), 15, -1, 90);
    }

    public function test_rejects_a_maximum_advance_below_one_day(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least 1 day, got 0');

        new BookingSettings(new DateTimeZone('Europe/London'), 15, 0, 0);
    }
}
