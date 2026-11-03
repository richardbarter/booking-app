<?php

namespace App\Models\Concerns;

use App\Models\Business;
use App\Models\Scopes\BusinessScope;
use App\Services\CurrentBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For every tenant-owned model: its queries only see the current business's
 * rows, and a new row with no business_id gets the current business's.
 */
trait BelongsToBusiness
{
    public static function bootBelongsToBusiness(): void
    {
        static::addGlobalScope(new BusinessScope);

        static::creating(function (Model $model): void {
            $currentBusiness = app(CurrentBusiness::class);

            if ($model->getAttribute('business_id') === null && $currentBusiness->has()) {
                $model->setAttribute('business_id', $currentBusiness->id());
            }
        });
    }

    /**
     * @return BelongsTo<Business, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
