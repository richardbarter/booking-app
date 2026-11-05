<?php

namespace App\Enums;

/**
 * A booking's status. Confirmed and no-show bookings block their time;
 * cancelled ones don't. "Attended" isn't stored: a confirmed booking whose
 * end has passed counts as attended (Booking::attended()).
 */
enum BookingStatus: string
{
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';
}
