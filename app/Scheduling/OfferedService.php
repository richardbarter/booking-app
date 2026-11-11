<?php

namespace App\Scheduling;

use InvalidArgumentException;

/**
 * A service the practitioner offers, as the waste score sees it: the diary
 * time it takes, and its room weight, how much the score protects room for
 * it, from 0 (not at all) to 10 (fully).
 */
final readonly class OfferedService
{
    public const int MAX_ROOM_WEIGHT = 10;

    public function __construct(
        public ServiceTimes $times,
        public int $roomWeight = self::MAX_ROOM_WEIGHT,
    ) {
        if ($roomWeight < 0 || $roomWeight > self::MAX_ROOM_WEIGHT) {
            throw new InvalidArgumentException(sprintf(
                'A room weight must be from 0 to %d, got %d.',
                self::MAX_ROOM_WEIGHT,
                $roomWeight,
            ));
        }
    }
}
