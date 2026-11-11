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

    /**
     * The diary time one appointment takes: its length and both buffers.
     */
    public function diaryMinutes(): int
    {
        return $this->bufferBeforeMinutes + $this->durationMinutes + $this->bufferAfterMinutes;
    }

    /**
     * Whether the two take the same diary time in the same way, with the
     * same length and buffers.
     */
    public function equals(self $other): bool
    {
        return $this->durationMinutes === $other->durationMinutes
            && $this->bufferBeforeMinutes === $other->bufferBeforeMinutes
            && $this->bufferAfterMinutes === $other->bufferAfterMinutes;
    }
}
