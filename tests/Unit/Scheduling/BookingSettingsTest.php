<?php

namespace Tests\Unit\Scheduling;

use App\Scheduling\BookingSettings;
use Carbon\CarbonImmutable;
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
        return ['10 minutes' => [10], '15 minutes' => [15], '20 minutes' => [20], '30 minutes' => [30], '60 minutes' => [60]];
    }

    #[DataProvider('slotIntervals')]
    public function test_accepts_each_slot_interval(int $minutes): void
    {
        $settings = new BookingSettings(new DateTimeZone('Europe/London'), $minutes, 120, 90, 50, 48);

        $this->assertSame($minutes, $settings->slotIntervalMinutes);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function otherIntervals(): array
    {
        return ['0 minutes' => [0], '5 minutes' => [5], '45 minutes' => [45], '120 minutes' => [120]];
    }

    #[DataProvider('otherIntervals')]
    public function test_rejects_any_other_slot_interval(int $minutes): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be 10, 15, 20, 30, 60 minutes');

        new BookingSettings(new DateTimeZone('Europe/London'), $minutes, 120, 90, 50, 48);
    }

    public function test_keeps_no_notice_and_a_one_day_advance(): void
    {
        $settings = new BookingSettings(new DateTimeZone('Asia/Kathmandu'), 15, 0, 1, 50, 48);

        $this->assertSame('Asia/Kathmandu', $settings->timezone->getName());
        $this->assertSame(0, $settings->minNoticeMinutes);
        $this->assertSame(1, $settings->maxAdvanceDays);
    }

    public function test_rejects_negative_notice(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('minimum notice can\'t be negative');

        new BookingSettings(new DateTimeZone('Europe/London'), 15, -1, 90, 50, 48);
    }

    public function test_rejects_a_maximum_advance_below_one_day(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least 1 day, got 0');

        new BookingSettings(new DateTimeZone('Europe/London'), 15, 0, 0, 50, 48);
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function strictnessAndRelaxationAtTheirLimits(): array
    {
        return [
            'the loosest, with strictness always applying' => [0, 0],
            'the tightest, relaxing 30 days ahead' => [100, 720],
        ];
    }

    #[DataProvider('strictnessAndRelaxationAtTheirLimits')]
    public function test_keeps_strictness_and_a_relaxation_window_at_their_limits(int $strictness, int $relaxationHours): void
    {
        $settings = new BookingSettings(new DateTimeZone('Europe/London'), 15, 120, 90, $strictness, $relaxationHours);

        $this->assertSame([$strictness, $relaxationHours], [$settings->strictness, $settings->relaxationHours]);
    }

    /**
     * @return array<string, array{int, int, string}>
     */
    public static function strictnessOrRelaxationOutOfRange(): array
    {
        return [
            'strictness below 0' => [-1, 48, 'The strictness must be from 0 to 100, got -1.'],
            'strictness above 100' => [101, 48, 'The strictness must be from 0 to 100, got 101.'],
            'a negative relaxation window' => [50, -1, 'The relaxation window must be from 0 to 720 hours, got -1.'],
            'a relaxation window over 30 days' => [50, 721, 'The relaxation window must be from 0 to 720 hours, got 721.'],
        ];
    }

    #[DataProvider('strictnessOrRelaxationOutOfRange')]
    public function test_rejects_strictness_or_a_relaxation_window_out_of_range(int $strictness, int $relaxationHours, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new BookingSettings(new DateTimeZone('Europe/London'), 15, 120, 90, $strictness, $relaxationHours);
    }

    public function test_the_strictness_at_any_time_is_the_business_strictness(): void
    {
        $settings = new BookingSettings(new DateTimeZone('Europe/London'), 15, 120, 90, 70, 48);

        $this->assertSame(
            [70, 70, 70],
            [
                $settings->strictnessAt(CarbonImmutable::parse('2026-11-09 09:00', 'Europe/London')),
                $settings->strictnessAt(CarbonImmutable::parse('2026-11-14 17:45', 'Europe/London')),
                $settings->strictnessAt(CarbonImmutable::parse('2027-03-28 01:00', 'UTC')),
            ],
        );
    }
}
