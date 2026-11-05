<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when slots are asked for, or a booking is made, for a service
 * that isn't active. Pages catch it for the rare case of a service
 * switched off while a customer was choosing a time.
 */
class ServiceNotBookable extends RuntimeException
{
    public function __construct(public readonly int $serviceId)
    {
        parent::__construct(sprintf('Service %d isn\'t active, so it can\'t be booked.', $serviceId));
    }
}
