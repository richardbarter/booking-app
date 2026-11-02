<?php

namespace Database\Factories\Concerns;

use App\Models\Practitioner;
use App\Models\Scopes\BusinessScope;
use LogicException;

/**
 * For factories of a practitioner's children: business_id always comes from
 * the practitioner, so a test can't create rows that mix two businesses.
 */
trait TakesBusinessFromPractitioner
{
    /**
     * The practitioner is read without the tenant scope, since tests often
     * create data for several businesses with no current business set.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function practitionersBusinessId(array $attributes): int
    {
        $practitionerId = $attributes['practitioner_id'] ?? null;

        if (! is_int($practitionerId)) {
            throw new LogicException('A practitioner is needed to know which business the row belongs to.');
        }

        return Practitioner::withoutGlobalScope(BusinessScope::class)->findOrFail($practitionerId)->business_id;
    }
}
