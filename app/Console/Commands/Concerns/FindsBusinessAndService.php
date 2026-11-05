<?php

namespace App\Console\Commands\Concerns;

use App\Models\Business;
use App\Models\Service;
use Illuminate\Console\Command;

/**
 * For commands that take a business ID and a service ID as their business
 * and service arguments. Each lookup prints an error and returns null when
 * there's no such row. The service is looked up within the current
 * business, so another business's service isn't found.
 *
 * @phpstan-require-extends Command
 */
trait FindsBusinessAndService
{
    private function findBusiness(): ?Business
    {
        $businessId = $this->parseId($this->argument('business'));
        $business = $businessId === null ? null : Business::query()->find($businessId);

        if ($business === null) {
            $this->error(sprintf('There\'s no business with ID %s.', $this->argument('business')));
        }

        return $business;
    }

    private function findService(Business $business): ?Service
    {
        $serviceId = $this->parseId($this->argument('service'));
        $service = $serviceId === null ? null : Service::query()->find($serviceId);

        if ($service === null) {
            $this->error(sprintf('Business %d has no service with ID %s.', $business->id, $this->argument('service')));
        }

        return $service;
    }

    /**
     * A database ID from an argument, or null if it isn't a whole number
     * of 1 or more.
     */
    private function parseId(mixed $argument): ?int
    {
        $id = filter_var($argument, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }
}
