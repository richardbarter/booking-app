<?php

namespace App\Console\Commands\Concerns;

use App\Models\Business;
use App\Scheduling\BookingSettings;
use Illuminate\Console\Command;

/**
 * For commands with a --strictness option, which try a business at another
 * strictness (07) without saving it. The value is set on the business the
 * command has loaded, and AvailabilityService, and CreateBooking through
 * it, read it from there. Nothing in these commands saves the business, so
 * the stored strictness never changes.
 *
 * @phpstan-require-extends Command
 */
trait TriesAnotherStrictness
{
    /**
     * Sets the --strictness option's value on the business, if it was
     * given. Returns false, after printing why, when it isn't a whole
     * number from 0 to 100.
     */
    private function tryStrictnessOption(Business $business): bool
    {
        $option = $this->option('strictness');

        if ($option === null) {
            return true;
        }

        $strictness = filter_var($option, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => BookingSettings::MAX_STRICTNESS]]);

        if ($strictness === false) {
            $this->error(sprintf('The strictness must be a whole number from 0 to %d, got "%s".', BookingSettings::MAX_STRICTNESS, $option));

            return false;
        }

        $business->strictness = $strictness;

        return true;
    }
}
