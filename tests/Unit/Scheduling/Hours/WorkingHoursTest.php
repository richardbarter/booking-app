<?php

namespace Tests\Unit\Scheduling\Hours;

use App\Scheduling\Hours\DayHours;
use App\Scheduling\Hours\PatternWeeks;
use App\Scheduling\Hours\RepeatingPattern;
use App\Scheduling\Hours\WorkingHours;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WorkingHoursTest extends TestCase
{
    public function test_an_edited_day_replaces_the_patterns_hours(): void
    {
        $hours = new WorkingHours(
            [$this->weekdays('2026-11-02', null, '09:00', '17:00')],
            ['2026-11-04' => DayHours::fromArray([['10:00', '16:00']])],
        );

        $this->assertSame([['10:00', '16:00']], $this->on($hours, '2026-11-04'));
        $this->assertSame([['09:00', '17:00']], $this->on($hours, '2026-11-05'));
    }

    public function test_an_empty_edited_day_means_no_hours(): void
    {
        $hours = new WorkingHours(
            [$this->weekdays('2026-11-02', null, '09:00', '17:00')],
            ['2026-11-04' => new DayHours],
        );

        $this->assertSame([], $this->on($hours, '2026-11-04'));
    }

    public function test_an_edited_day_works_with_no_pattern_in_force(): void
    {
        $hours = new WorkingHours([], ['2026-11-07' => DayHours::fromArray([['09:00', '13:00']])]);

        $this->assertSame([['09:00', '13:00']], $this->on($hours, '2026-11-07'));
        $this->assertSame([], $this->on($hours, '2026-11-09'));
    }

    public function test_finds_an_edited_day_from_a_time_in_another_zone(): void
    {
        $hours = new WorkingHours([], ['2026-11-10' => DayHours::fromArray([['09:00', '13:00']])]);

        $day = $hours->on(CarbonImmutable::parse('2026-11-10 08:00', 'Pacific/Auckland'));

        $this->assertSame([['09:00', '13:00']], $day->toArray());
    }

    public function test_there_are_no_hours_before_the_first_pattern(): void
    {
        $hours = new WorkingHours([$this->weekdays('2026-11-02', null, '09:00', '17:00')], []);

        $this->assertSame([], $this->on($hours, '2026-10-30'));
        $this->assertSame([['09:00', '17:00']], $this->on($hours, '2026-11-02'));
    }

    public function test_there_are_no_hours_after_a_patterns_end_date(): void
    {
        $hours = new WorkingHours([$this->weekdays('2026-11-02', '2026-11-13', '09:00', '17:00')], []);

        $this->assertSame([['09:00', '17:00']], $this->on($hours, '2026-11-13'));
        $this->assertSame([], $this->on($hours, '2026-11-16'));
    }

    public function test_there_are_no_hours_in_a_gap_between_patterns(): void
    {
        $hours = new WorkingHours([
            $this->weekdays('2026-11-02', '2026-11-13', '09:00', '17:00'),
            $this->weekdays('2026-11-23', null, '10:00', '14:00'),
        ], []);

        $this->assertSame([], $this->on($hours, '2026-11-18'));
        $this->assertSame([['10:00', '14:00']], $this->on($hours, '2026-11-23'));
    }

    public function test_uses_each_back_to_back_pattern_for_its_own_dates(): void
    {
        $hours = new WorkingHours([
            $this->weekdays('2026-11-16', null, '10:00', '14:00'),
            $this->weekdays('2026-11-02', '2026-11-15', '09:00', '17:00'),
        ], []);

        $this->assertSame([['09:00', '17:00']], $this->on($hours, '2026-11-13'));
        $this->assertSame([['10:00', '14:00']], $this->on($hours, '2026-11-16'));
    }

    /**
     * @return array<string, array{array{string, ?string}, array{string, ?string}}>
     */
    public static function overlappingPatterns(): array
    {
        return [
            'overlapping by one day' => [['2026-11-02', '2026-11-16'], ['2026-11-16', null]],
            'a later pattern overlapping an open-ended one' => [['2026-11-02', null], ['2027-01-04', '2027-01-29']],
            'the same start' => [['2026-11-02', '2026-11-06'], ['2026-11-02', '2026-11-06']],
            'given latest first' => [['2026-11-16', null], ['2026-11-02', '2026-11-20']],
        ];
    }

    /**
     * @param  array{string, ?string}  $first
     * @param  array{string, ?string}  $second
     */
    #[DataProvider('overlappingPatterns')]
    public function test_rejects_overlapping_patterns(array $first, array $second): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('can\'t overlap');

        new WorkingHours([
            $this->weekdays($first[0], $first[1], '09:00', '17:00'),
            $this->weekdays($second[0], $second[1], '09:00', '17:00'),
        ], []);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function keysThatAreNotDates(): array
    {
        return [
            'one-digit day' => ['2026-11-4'],
            'a date that does not exist' => ['2026-02-30'],
            'day first' => ['04/11/2026'],
            'with a time' => ['2026-11-04 00:00:00'],
        ];
    }

    #[DataProvider('keysThatAreNotDates')]
    public function test_rejects_edited_days_not_keyed_by_y_m_d_dates(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('keyed by Y-m-d dates');

        new WorkingHours([], [$key => new DayHours]);
    }

    /**
     * A weekly pattern working the same hours Monday to Friday. Its cycle
     * starts on the Monday of its first week.
     */
    private function weekdays(string $startsOn, ?string $endsOn, string $from, string $until): RepeatingPattern
    {
        $startsOnDate = CarbonImmutable::parse($startsOn);
        $workingDay = [[$from, $until]];

        return new RepeatingPattern(
            $startsOnDate,
            $endsOn === null ? null : CarbonImmutable::parse($endsOn),
            $startsOnDate->startOfWeek(CarbonImmutable::MONDAY),
            PatternWeeks::fromArray([[
                'mon' => $workingDay, 'tue' => $workingDay, 'wed' => $workingDay, 'thu' => $workingDay,
                'fri' => $workingDay, 'sat' => [], 'sun' => [],
            ]]),
        );
    }

    /**
     * @return list<array{string, string}>
     */
    private function on(WorkingHours $hours, string $date): array
    {
        return $hours->on(CarbonImmutable::parse($date))->toArray();
    }
}
