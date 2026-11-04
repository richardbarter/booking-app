<?php

namespace App\Scheduling;

use DateTimeZone;
use InvalidArgumentException;

/**
 * The business's booking settings that the engine needs: its time zone,
 * the step between start times, and the booking window.
 */
final readonly class BookingSettings
{
    /**
     * @var list<int>
     */
    public const array SLOT_INTERVALS = [10, 15, 20, 30, 60];

    public function __construct(
        public DateTimeZone $timezone,
        public int $slotIntervalMinutes,
        public int $minNoticeMinutes,
        public int $maxAdvanceDays,
    ) {
        if (! in_array($slotIntervalMinutes, self::SLOT_INTERVALS, true)) {
            throw new InvalidArgumentException(sprintf(
                'The slot interval must be %s minutes, got %d.',
                implode(', ', self::SLOT_INTERVALS),
                $slotIntervalMinutes,
            ));
        }

        if ($minNoticeMinutes < 0) {
            throw new InvalidArgumentException(sprintf('The minimum notice can\'t be negative, got %d minutes.', $minNoticeMinutes));
        }

        if ($maxAdvanceDays < 1) {
            throw new InvalidArgumentException(sprintf('The maximum advance must be at least 1 day, got %d.', $maxAdvanceDays));
        }
    }
}
