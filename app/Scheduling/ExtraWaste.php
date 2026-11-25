<?php

namespace App\Scheduling;

/**
 * How a slot's waste score differs from the best slot's in its free gap,
 * to say why strictness hides it (08): the extra minutes it would strand,
 * the kinds of appointment it takes more room from, and whether it splits
 * the gap when the best doesn't. Comparing the two leaves out the waste
 * that no time in the gap can avoid. Parts where the slot does better than
 * the best are left out too.
 *
 * A slot with extra waste above 0 always has at least one part, since its
 * score couldn't be higher if no part of it were (rule 20).
 */
final readonly class ExtraWaste
{
    /**
     * @param  list<array{times: ServiceTimes, fewer: int}>  $lostRoom  each kind of appointment the gap could take fewer of than after the best, and how many fewer, in the slot's order
     */
    public function __construct(
        public int $strandedMinutes,
        public array $lostRoom,
        public bool $splits,
    ) {}

    /**
     * How a slot's score differs from the best's. Give the scores of two
     * slots in the same free gap, as DayAvailability::bestInGapOf() pairs
     * them.
     */
    public static function between(WasteScore $slot, WasteScore $best): self
    {
        return new self(
            max(0, $slot->strandedMinutes - $best->strandedMinutes),
            self::lostRoomBetween($slot, $best),
            $slot->splitMinutes > $best->splitMinutes,
        );
    }

    /**
     * @return list<array{times: ServiceTimes, fewer: int}>
     */
    private static function lostRoomBetween(WasteScore $slot, WasteScore $best): array
    {
        $lostRoom = [];

        foreach ($slot->lostRoom as ['times' => $times, 'fewer' => $fewer]) {
            $more = $fewer - $best->fewerOf($times);

            if ($more > 0) {
                $lostRoom[] = ['times' => $times, 'fewer' => $more];
            }
        }

        return $lostRoom;
    }
}
