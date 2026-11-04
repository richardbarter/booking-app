<?php

namespace App\Scheduling;

use InvalidArgumentException;

/**
 * How much diary time a service takes: its length, and the buffers it
 * blocks before and after, in real minutes.
 */
final readonly class ServiceTimes
{
    public function __construct(
        public int $durationMinutes,
        public int $bufferBeforeMinutes = 0,
        public int $bufferAfterMinutes = 0,
    ) {
        if ($durationMinutes < 1) {
            throw new InvalidArgumentException(sprintf('A service must last at least 1 minute, got %d.', $durationMinutes));
        }

        if ($bufferBeforeMinutes < 0 || $bufferAfterMinutes < 0) {
            throw new InvalidArgumentException(sprintf(
                'A service\'s buffers can\'t be negative, got %d before and %d after.',
                $bufferBeforeMinutes,
                $bufferAfterMinutes,
            ));
        }
    }
}
