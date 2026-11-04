<?php

namespace Tests\Unit\Scheduling;

use App\Scheduling\BookingSettings;
use App\Scheduling\BookingWindow;
use Carbon\CarbonImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

class BookingWindowTest extends TestCase
{
    public function test_the_earliest_start_is_now_plus_the_minimum_notice(): void
    {
        $window = $this->window('Europe/London', 120, 90, '2026-11-02 08:00:30');

        $this->assertSame('2026-11-02T10:00:30+00:00', $window->earliestStart()->toIso8601String());
    }

    public function test_allows_a_start_exactly_at_the_minimum_notice_and_not_a_second_sooner(): void
    {
        $window = $this->window('Europe/London', 120, 90, '2026-11-02 08:00');

        $this->assertTrue($window->allows($this->utc('2026-11-02 10:00:00')));
        $this->assertFalse($window->allows($this->utc('2026-11-02 09:59:59')));
    }

    public function test_with_no_notice_the_earliest_start_is_now(): void
    {
        $window = $this->window('Europe/London', 0, 90, '2026-11-02 08:00');

        $this->assertTrue($window->allows($this->utc('2026-11-02 08:00')));
        $this->assertFalse($window->allows($this->utc('2026-11-02 07:59')));
    }

    public function test_the_notice_is_in_real_minutes_across_a_clock_change(): void
    {
        $window = $this->window('Europe/London', 120, 90, '2027-03-28 00:30');

        $this->assertSame('2027-03-28T02:30:00+00:00', $window->earliestStart()->toIso8601String());
    }

    public function test_the_last_date_is_today_plus_the_maximum_advance(): void
    {
        $window = $this->window('Europe/London', 120, 90, '2026-11-02 08:00');

        $this->assertSame('2027-01-31', $window->lastDate());
    }

    public function test_allows_any_time_on_the_last_date_and_none_on_the_date_after(): void
    {
        $window = $this->window('Europe/London', 120, 90, '2026-11-02 08:00');

        $this->assertTrue($window->allows($this->local('2027-01-31 23:45', 'Europe/London')));
        $this->assertFalse($window->allows($this->local('2027-02-01 00:00', 'Europe/London')));
    }

    public function test_today_follows_the_business_time_zone(): void
    {
        $window = $this->window('Pacific/Auckland', 0, 1, '2026-11-09 23:30');

        $this->assertSame('2026-11-11', $window->lastDate());
        $this->assertTrue($window->allows($this->local('2026-11-11 17:00', 'Pacific/Auckland')));
        $this->assertFalse($window->allows($this->local('2026-11-12 09:00', 'Pacific/Auckland')));
    }

    private function window(string $zone, int $minNoticeMinutes, int $maxAdvanceDays, string $nowInUtc): BookingWindow
    {
        return new BookingWindow(
            new BookingSettings(new DateTimeZone($zone), 15, $minNoticeMinutes, $maxAdvanceDays),
            $this->utc($nowInUtc),
        );
    }

    private function utc(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($time, 'UTC');
    }

    private function local(string $time, string $zone): CarbonImmutable
    {
        return CarbonImmutable::parse($time, $zone)->utc();
    }
}
