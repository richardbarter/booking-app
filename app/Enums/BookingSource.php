<?php

namespace App\Enums;

/**
 * Where a booking was made.
 */
enum BookingSource: string
{
    case Playground = 'playground';
    case BookingPage = 'booking_page';
    case Widget = 'widget';
    case Admin = 'admin';
}
