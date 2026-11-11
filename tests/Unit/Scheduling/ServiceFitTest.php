<?php

namespace Tests\Unit\Scheduling;

use App\Scheduling\LocalClock;
use App\Scheduling\ServiceFit;
use App\Scheduling\ServiceTimes;
use Carbon\CarbonImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pieces of time in London on Monday 9 November 2026 (when London is on
 * UTC) unless a test says otherwise, with start times every 15 minutes.
 * Times are local.
 */
class ServiceFitTest extends TestCase
{
    private const string MONDAY = '2026-11-09';

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function piecesForABufferedService(): array
    {
        return [
            'just long enough, starting at 09:15' => ['09:00', '09:50', true],
            'a minute short' => ['09:00', '09:49', false],
            'starting at 09:05, so the buffer before fits before 09:15' => ['09:05', '09:50', true],
            'starting at 09:06, so the first start is 09:30' => ['09:06', '09:50', false],
        ];
    }

    #[DataProvider('piecesForABufferedService')]
    public function test_a_kind_fits_when_a_start_time_puts_its_blocked_time_inside_the_piece(string $from, string $until, bool $expected): void
    {
        $fit = $this->fitOn(self::MONDAY);

        $this->assertSame($expected, $fit->fits(new ServiceTimes(30, 10, 5), ...$this->piece($from, $until)));
    }

    public function test_a_kind_does_not_fit_a_piece_long_enough_when_no_start_time_works(): void
    {
        $fit = $this->fitOn(self::MONDAY);

        $this->assertFalse($fit->fits(new ServiceTimes(30), ...$this->piece('10:07', '10:40')));
    }

    public function test_a_kind_with_a_buffer_before_needs_it_inside_the_piece(): void
    {
        $fit = $this->fitOn(self::MONDAY);
        $service = new ServiceTimes(30, bufferBeforeMinutes: 15);

        $this->assertSame(0, $fit->count($service, ...$this->piece('09:00', '09:30')));
        $this->assertSame(1, $fit->count($service, ...$this->piece('09:00', '09:45')));
    }

    public function test_buffers_add_up_back_to_back(): void
    {
        $fit = $this->fitOn(self::MONDAY);

        $this->assertSame(5, $fit->count(new ServiceTimes(30, bufferAfterMinutes: 15), ...$this->piece('09:00', '13:00')));
    }

    public function test_a_buffer_before_waits_for_the_appointment_before_to_end(): void
    {
        $fit = $this->fitOn(self::MONDAY);

        $this->assertSame(2, $fit->count(new ServiceTimes(30, bufferBeforeMinutes: 15), ...$this->piece('09:00', '11:00')));
    }

    /**
     * @return array<string, array{int, string, string, int}>
     */
    public static function piecesOffTheGrid(): array
    {
        return [
            'an hour from 10:07 holds one, at 10:15' => [30, '10:07', '11:07', 1],
            'two hours exactly holds four' => [30, '09:00', '11:00', 4],
            'the same two hours a minute late holds three' => [30, '09:01', '11:01', 3],
            'an hour and a half holds one 60' => [60, '09:00', '10:30', 1],
        ];
    }

    #[DataProvider('piecesOffTheGrid')]
    public function test_counts_the_most_that_fit_on_the_interval(int $durationMinutes, string $from, string $until, int $expected): void
    {
        $fit = $this->fitOn(self::MONDAY);

        $this->assertSame($expected, $fit->count(new ServiceTimes($durationMinutes), ...$this->piece($from, $until)));
    }

    public function test_an_empty_piece_holds_nothing(): void
    {
        $fit = $this->fitOn(self::MONDAY);
        [$moment] = $this->piece('09:00', '09:00');

        $this->assertFalse($fit->fits(new ServiceTimes(5), $moment, $moment));
        $this->assertSame(0, $fit->count(new ServiceTimes(5), $moment, $moment));
    }

    public function test_nothing_fits_after_the_dates_last_start_time(): void
    {
        $fit = $this->fitOn(self::MONDAY);
        [$from, $until] = $this->piece('23:50', '23:59');

        $this->assertFalse($fit->fits(new ServiceTimes(5), $from, $until + 3600));
    }

    public function test_counts_real_time_on_the_night_the_clocks_go_forward(): void
    {
        $fit = $this->fitOn('2027-03-28');

        $this->assertSame(2, $fit->count(new ServiceTimes(60), ...$this->piece('00:00', '03:00', '2027-03-28')));
    }

    private function fitOn(string $date): ServiceFit
    {
        return new ServiceFit((new LocalClock(new DateTimeZone('Europe/London')))->startTimesOn(CarbonImmutable::parse($date, 'UTC'), 15));
    }

    /**
     * A piece of a date, from and until in whole seconds.
     *
     * @return array{int, int}
     */
    private function piece(string $from, string $until, string $date = self::MONDAY): array
    {
        return [
            CarbonImmutable::parse("{$date} {$from}", 'Europe/London')->getTimestamp(),
            CarbonImmutable::parse("{$date} {$until}", 'Europe/London')->getTimestamp(),
        ];
    }
}
