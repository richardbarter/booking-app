<?php

namespace App\Exceptions;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RuntimeException;
use Throwable;

/**
 * Thrown when a start time can't be booked, whatever the reason: it was
 * just taken, or it's not on the slot interval, outside working hours,
 * during time off or outside the booking window. Pages show the customer
 * the same "Sorry, that time was just taken" either way, with the updated
 * times. The message mentions IDs and times, never customer details.
 */
class SlotNotAvailable extends RuntimeException
{
    public readonly CarbonImmutable $startsAt;

    public function __construct(public readonly int $serviceId, CarbonInterface $startsAt, ?Throwable $previous = null)
    {
        $this->startsAt = $startsAt->toImmutable()->utc();

        parent::__construct(
            sprintf('Service %d can\'t be booked at %s: it isn\'t one of the times offered.', $serviceId, $this->startsAt->toIso8601String()),
            0,
            $previous,
        );
    }
}
