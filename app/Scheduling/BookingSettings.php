<?php

namespace App\Scheduling;

use Carbon\CarbonImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * The business's booking settings that the engine needs: its time zone,
 * the step between start times, the booking window, and how tightly the
 * diary is packed (07): strictness from 0 to 100, and the relaxation
 * window, the hours before each time when strictness stops applying.
 */
final readonly class BookingSettings
{
    /**
     * @var list<int>
     */
    public const array SLOT_INTERVALS = [10, 15, 20, 30, 60];

    public const int MAX_STRICTNESS = 100;

    /**
     * 30 days.
     */
    public const int MAX_RELAXATION_HOURS = 720;

    public function __construct(
        public DateTimeZone $timezone,
        public int $slotIntervalMinutes,
        public int $minNoticeMinutes,
        public int $maxAdvanceDays,
        public int $strictness,
        public int $relaxationHours,
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

        if ($strictness < 0 || $strictness > self::MAX_STRICTNESS) {
            throw new InvalidArgumentException(sprintf('The strictness must be from 0 to %d, got %d.', self::MAX_STRICTNESS, $strictness));
        }

        if ($relaxationHours < 0 || $relaxationHours > self::MAX_RELAXATION_HOURS) {
            throw new InvalidArgumentException(sprintf('The relaxation window must be from 0 to %d hours, got %d.', self::MAX_RELAXATION_HOURS, $relaxationHours));
        }
    }

    /**
     * The strictness that applies to a time starting then. In v1 it's the
     * business's strictness at every time. Strictness by time of day
     * (overview, 5.4) changes only this method and the data behind it.
     */
    public function strictnessAt(CarbonImmutable $startsAt): int
    {
        return $this->strictness;
    }
}
