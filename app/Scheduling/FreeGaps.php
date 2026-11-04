<?php

namespace App\Scheduling;

/**
 * Works out free gaps: the stretches when a practitioner is working and
 * not blocked.
 */
final class FreeGaps
{
    /**
     * Each free gap is as long as it can be. Blocked times may overlap or
     * touch each other, and start before or end after the working times.
     *
     * @param  list<TimeRange>  $workingTimes  in time order, never touching, as LocalClock::workingTimes() gives them
     * @param  list<TimeRange>  $blockedTimes  in any order
     * @return list<TimeRange>
     */
    public static function between(array $workingTimes, array $blockedTimes): array
    {
        usort($blockedTimes, fn (TimeRange $first, TimeRange $second): int => $first->start <=> $second->start);
        $gaps = [];

        foreach ($workingTimes as $workingTime) {
            $freeFrom = $workingTime->start;

            foreach ($blockedTimes as $blocked) {
                $freeUntil = min($blocked->start, $workingTime->end);

                if ($freeFrom < $freeUntil) {
                    $gaps[] = new TimeRange($freeFrom, $freeUntil);
                }

                $freeFrom = max($freeFrom, $blocked->end);
            }

            if ($freeFrom < $workingTime->end) {
                $gaps[] = new TimeRange($freeFrom, $workingTime->end);
            }
        }

        return $gaps;
    }
}
