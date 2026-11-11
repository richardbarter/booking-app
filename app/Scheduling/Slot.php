<?php

namespace App\Scheduling;

use Carbon\CarbonImmutable;

/**
 * A start time the engine offers. The appointment runs from startsAt to
 * endsAt, and the diary is blocked from blockedFrom to blockedUntil, which
 * add the service's buffers. Gap is the free gap it sits in, and waste is
 * how much room booking it would cost (06).
 */
final readonly class Slot
{
    public function __construct(
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public CarbonImmutable $blockedFrom,
        public CarbonImmutable $blockedUntil,
        public TimeRange $gap,
        public WasteScore $waste,
    ) {}
}
