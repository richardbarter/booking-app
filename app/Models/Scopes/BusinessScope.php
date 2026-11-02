<?php

namespace App\Models\Scopes;

use App\Services\CurrentBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filters a tenant-owned model's queries by the current business, and throws
 * MissingCurrentBusiness when none is set. Code that really works across
 * businesses opts out with withoutGlobalScope(BusinessScope::class).
 *
 * @implements Scope<Model>
 */
class BusinessScope implements Scope
{
    /**
     * The current business is looked up for each query, rather than injected,
     * because one scope instance serves a model for the whole process while
     * CurrentBusiness is made afresh for each request or job.
     *
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('business_id'), app(CurrentBusiness::class)->id());
    }
}
