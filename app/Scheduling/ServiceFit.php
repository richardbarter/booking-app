<?php

namespace App\Scheduling;

use Carbon\CarbonImmutable;

/**
 * Fits appointments into a piece of free time on one date: whether one of
 * a kind fits, and how many fit back to back. An appointment fits when a
 * start time on the slot interval puts its blocked time, buffers included,
 * inside the piece, the test SlotFinder uses for slots. The start times
 * are the date's, from LocalClock::startTimesOn(), so clock changes work
 * as they do for slots.
 *
 * Pieces are in whole seconds, as SlotFinder counts, since the waste score
 * fits every kind of appointment into two pieces for each slot.
 */
final readonly class ServiceFit
{
    private const int SECONDS_IN_MINUTE = 60;

    /**
     * @var list<int>
     */
    private array $startTimes;

    /**
     * @param  list<CarbonImmutable>  $startTimes  in time order
     */
    public function __construct(array $startTimes)
    {
        $this->startTimes = array_map(fn (CarbonImmutable $startTime): int => $startTime->getTimestamp(), $startTimes);
    }

    /**
     * Whether one appointment of the kind fits in the piece.
     */
    public function fits(ServiceTimes $kind, int $from, int $until): bool
    {
        return $this->endOfFirst($kind, $from, $until) !== null;
    }

    /**
     * The most appointments of the kind that fit in the piece without
     * overlapping. Each goes at the earliest start that fits once the one
     * before it, buffers included, has ended. For appointments that all
     * take the same time, that always finds the most that fit: the
     * standard result for scheduling equal-length intervals.
     */
    public function count(ServiceTimes $kind, int $from, int $until): int
    {
        $count = 0;

        while (($blockedUntil = $this->endOfFirst($kind, $from, $until)) !== null) {
            $count++;
            $from = $blockedUntil;
        }

        return $count;
    }

    /**
     * When the blocked time of the earliest appointment of the kind that
     * fits in the piece ends, or null when none fits.
     */
    private function endOfFirst(ServiceTimes $kind, int $from, int $until): ?int
    {
        $startsAt = $this->firstStartAtOrAfter($from + $kind->bufferBeforeMinutes * self::SECONDS_IN_MINUTE);

        if ($startsAt === null) {
            return null;
        }

        [, $blockedUntil] = $kind->blockedAround($startsAt);

        return $blockedUntil <= $until ? $blockedUntil : null;
    }

    /**
     * The first start time at or after a moment, found by halving the list,
     * or null when there's none.
     */
    private function firstStartAtOrAfter(int $moment): ?int
    {
        $low = 0;
        $high = count($this->startTimes);

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);

            if ($this->startTimes[$middle] < $moment) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $this->startTimes[$low] ?? null;
    }
}
