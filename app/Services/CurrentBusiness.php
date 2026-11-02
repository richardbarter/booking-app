<?php

namespace App\Services;

use App\Exceptions\MissingCurrentBusiness;
use App\Models\Business;
use Closure;
use Illuminate\Container\Attributes\Scoped;

/**
 * The business a request or job is working for. Every query on a tenant-owned
 * model is filtered by it (BusinessScope). The container makes one for each
 * request or queue job and throws it away afterwards.
 */
#[Scoped]
class CurrentBusiness
{
    private ?Business $business = null;

    public function set(Business $business): void
    {
        $this->business = $business;
    }

    /**
     * @throws MissingCurrentBusiness
     */
    public function get(): Business
    {
        return $this->business ?? throw new MissingCurrentBusiness;
    }

    /**
     * @throws MissingCurrentBusiness
     */
    public function id(): int
    {
        return $this->get()->id;
    }

    public function has(): bool
    {
        return $this->business !== null;
    }

    public function forget(): void
    {
        $this->business = null;
    }

    /**
     * Run the callback with the given business as the current one, then
     * restore the previous one, even if the callback throws.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(Business $business, Closure $callback): mixed
    {
        $previous = $this->business;
        $this->business = $business;

        try {
            return $callback();
        } finally {
            $this->business = $previous;
        }
    }
}
