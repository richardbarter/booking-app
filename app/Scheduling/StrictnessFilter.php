<?php

namespace App\Scheduling;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Strictness (07): the engine's last step, after the waste score. It splits
 * a day's slots into the times offered and the ones hidden. A slot is
 * hidden when all three hold:
 * 1. the strictness at its start is above 0
 * 2. it starts after the relaxation window: more than the relaxation
 *    hours after now, in real hours, so a time exactly that far ahead is
 *    still inside it
 * 3. its extra waste is above the threshold of MAX_THRESHOLD_MINUTES ×
 *    (100 − strictness) ÷ 100 minutes, compared in whole numbers, so a
 *    slot exactly on the threshold is offered
 *
 * So 0 hides nothing, 50 hides slots that waste more than 60 minutes more
 * than the best in their free gap, and 100 keeps only the best. The best
 * slot in each gap has extra waste 0, which is never above a threshold, so
 * every gap that offers a time at 0 offers one at any strictness.
 *
 * The 120 minutes is a first version, for the playground (08) to tune.
 */
final readonly class StrictnessFilter
{
    public const int MAX_THRESHOLD_MINUTES = 120;

    private CarbonImmutable $relaxationEnds;

    public function __construct(private BookingSettings $settings, CarbonInterface $now)
    {
        $this->relaxationEnds = $now->toImmutable()->utc()->addHours($settings->relaxationHours);
    }

    /**
     * The slots offered and the slots hidden, each in the order given.
     *
     * @param  list<Slot>  $slots
     * @return array{list<Slot>, list<Slot>}
     */
    public function split(array $slots): array
    {
        $offered = [];
        $hidden = [];

        foreach ($slots as $slot) {
            if ($this->hides($slot)) {
                $hidden[] = $slot;
            } else {
                $offered[] = $slot;
            }
        }

        return [$offered, $hidden];
    }

    private function hides(Slot $slot): bool
    {
        $strictness = $this->settings->strictnessAt($slot->startsAt);

        return $strictness > 0
            && $slot->startsAt > $this->relaxationEnds
            && BookingSettings::MAX_STRICTNESS * $slot->waste->extraMinutes > self::MAX_THRESHOLD_MINUTES * (BookingSettings::MAX_STRICTNESS - $strictness);
    }
}
