<?php

namespace App\Scheduling;

/**
 * How much room booking a slot would cost the bookings still to come, in
 * whole minutes, worked out from the diary alone, so the same slot always
 * gets the same score. It adds up:
 * - stranded: the time it would leave before or after it, in pieces that
 *   no offered service fits, whatever its room weight
 * - lost room: for each kind of appointment, how many fewer its free gap
 *   could still take, valued at the diary time each takes and averaged by
 *   room weight
 * - split: a small cost when it would leave free time on both sides
 *
 * Extra waste is how much more it is than the lowest score among the slots
 * offered in the same free gap, so the best there has 0. Strictness (07)
 * compares extra waste, since some of every score can't be avoided.
 */
final readonly class WasteScore
{
    public int $minutes;

    /**
     * @param  list<array{times: ServiceTimes, fewer: int}>  $lostRoom  each kind of appointment with a room weight above 0 that the gap could take fewer of, and how many fewer, to explain the lost room
     */
    public function __construct(
        public int $strandedMinutes,
        public int $lostRoomMinutes,
        public int $splitMinutes,
        public int $extraMinutes,
        public array $lostRoom,
    ) {
        $this->minutes = $strandedMinutes + $lostRoomMinutes + $splitMinutes;
    }
}
