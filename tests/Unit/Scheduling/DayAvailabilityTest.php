<?php

namespace Tests\Unit\Scheduling;

use App\Scheduling\BookingSettings;
use App\Scheduling\DateRange;
use App\Scheduling\DayAvailability;
use App\Scheduling\Diary;
use App\Scheduling\ExtraWaste;
use App\Scheduling\Hours\DayHours;
use App\Scheduling\Hours\WorkingHours;
use App\Scheduling\OfferedService;
use App\Scheduling\ServiceTimes;
use App\Scheduling\Slot;
use App\Scheduling\SlotFinder;
use Carbon\CarbonImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The best slot in each free gap, through SlotFinder for the sample
 * business's follow-up on Monday 9 November 2026, free from 09:00 to 13:00
 * and 14:00 to 17:30, at strictness 75, from a week before. In the morning
 * the follow-up's least wasteful times are 09:00 and 12:30, and in the
 * afternoon 14:00 and 17:00. Times are in London.
 */
class DayAvailabilityTest extends TestCase
{
    private const string MONDAY = '2026-11-09';

    /**
     * Each time's extra waste, whether it's hidden, and the best in its
     * gap.
     *
     * @return array<string, array{string, int, bool, string}>
     */
    public static function slotsAndTheirBest(): array
    {
        return [
            'a hidden slot' => ['09:15', 32, true, '09:00'],
            'an offered slot' => ['09:30', 5, false, '09:00'],
            'the best itself' => ['09:00', 0, false, '09:00'],
            'one that ties with the best' => ['12:30', 0, false, '09:00'],
            'a hidden slot in the next gap' => ['14:15', 74, true, '14:00'],
            'one that ties in the next gap' => ['17:00', 0, false, '14:00'],
        ];
    }

    #[DataProvider('slotsAndTheirBest')]
    public function test_finds_the_earliest_least_wasteful_slot_in_the_same_gap(string $time, int $extraMinutes, bool $hidden, string $best): void
    {
        $day = $this->monday();
        $slot = $this->slotAt($day, $time);

        $found = $day->bestInGapOf($slot);

        $this->assertSame($this->slotAt($day, $best), $found);
        $this->assertSame([$extraMinutes, $hidden], [$slot->waste->extraMinutes, in_array($slot, $day->hiddenSlots, true)]);
    }

    public function test_the_sample_mornings_09_15_follow_up_strands_15_minutes_more_than_09_00(): void
    {
        $day = $this->monday();
        $slot = $this->slotAt($day, '09:15');

        $extra = ExtraWaste::between($slot->waste, $day->bestInGapOf($slot)->waste);

        $this->assertSame(15, $extra->strandedMinutes);
    }

    public function test_rejects_a_slot_from_another_day(): void
    {
        $slot = $this->slotAt($this->day('2026-11-10'), '09:15');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The slot at 2026-11-10T09:15:00+00:00 isn\'t one of those on 2026-11-09');

        $this->monday()->bestInGapOf($slot);
    }

    public function test_rejects_a_slot_in_the_same_gap_from_another_search(): void
    {
        $slot = $this->slotAt($this->day(self::MONDAY, new ServiceTimes(60)), '09:00');

        $this->expectException(InvalidArgumentException::class);

        $this->monday()->bestInGapOf($slot);
    }

    private function monday(): DayAvailability
    {
        return $this->day(self::MONDAY);
    }

    private function day(string $date, ServiceTimes $service = new ServiceTimes(30)): DayAvailability
    {
        $availability = (new SlotFinder)->find(
            new Diary(
                new WorkingHours([], [$date => DayHours::fromArray([['09:00', '13:00'], ['14:00', '17:30']])]),
                [new OfferedService(new ServiceTimes(30)), new OfferedService(new ServiceTimes(60)), new OfferedService(new ServiceTimes(90), 5)],
            ),
            $service,
            new BookingSettings(new DateTimeZone('Europe/London'), 15, 120, 90, strictness: 75, relaxationHours: 48),
            DateRange::between(CarbonImmutable::parse($date), CarbonImmutable::parse($date)),
            CarbonImmutable::parse('2026-11-02 08:00', 'Europe/London'),
        );

        $day = $availability->day($date);
        $this->assertNotNull($day);

        return $day;
    }

    private function slotAt(DayAvailability $day, string $time): Slot
    {
        foreach ([...$day->slots, ...$day->hiddenSlots] as $slot) {
            if ($slot->startsAt->setTimezone('Europe/London')->format('H:i') === $time) {
                return $slot;
            }
        }

        $this->fail("No slot at {$time}.");
    }
}
