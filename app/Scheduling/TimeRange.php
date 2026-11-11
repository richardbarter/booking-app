<?php

namespace App\Scheduling;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * A stretch of real time, held in UTC. It includes its start but not its
 * end, as PostgreSQL's '[)' ranges do, so two ranges that only touch don't
 * overlap: a booking ending at 10:00 and one starting at 10:00 can both
 * be made.
 */
final readonly class TimeRange
{
    public CarbonImmutable $start;

    public CarbonImmutable $end;

    public function __construct(CarbonInterface $start, CarbonInterface $end)
    {
        $this->start = $start->toImmutable()->utc();
        $this->end = $end->toImmutable()->utc();

        if ($this->start >= $this->end) {
            throw new InvalidArgumentException(sprintf(
                'A time range must start before it ends, got %s to %s.',
                $this->start->toIso8601String(),
                $this->end->toIso8601String(),
            ));
        }
    }

    public function overlaps(self $other): bool
    {
        return $this->start < $other->end && $other->start < $this->end;
    }

    public function contains(self $other): bool
    {
        return $this->start <= $other->start && $other->end <= $this->end;
    }

    /**
     * Its start and end as Unix timestamps, rounded inwards to whole
     * seconds, so a range of whole seconds fits inside them exactly when it
     * fits inside this range. Blocked time can end part-way through a
     * second.
     *
     * @return array{int, int}
     */
    public function wholeSecondsInside(): array
    {
        return [$this->start->ceilSecond()->getTimestamp(), $this->end->floorSecond()->getTimestamp()];
    }
}
