<?php

namespace App\Exceptions;

use LogicException;

/**
 * Thrown when tenant-owned data is queried with no current business set. An
 * entry point that forgot to set the business fails loudly rather than
 * showing every business's data.
 */
class MissingCurrentBusiness extends LogicException
{
    public function __construct()
    {
        parent::__construct(
            'No current business is set. Set one with CurrentBusiness::set() or run() before querying '
            .'tenant-owned models, or opt out with withoutGlobalScope(BusinessScope::class) in code that '
            .'really works across businesses.',
        );
    }
}
