<?php

namespace App\Models;

use App\Casts\AsPatternWeeks;
use App\Models\Concerns\BelongsToBusiness;
use App\Scheduling\Hours\PatternWeeks;
use App\Scheduling\Hours\RepeatingPattern;
use Carbon\CarbonImmutable;
use Database\Factories\WorkingPatternFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A practitioner's repeating hours: a cycle of 1 to 4 weeks, in force from
 * starts_on until ends_on, or until replaced. Its weeks count from
 * cycle_starts_on, a Monday on or before starts_on.
 *
 * @property int $id
 * @property int $business_id
 * @property int $practitioner_id
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property CarbonImmutable $cycle_starts_on
 * @property PatternWeeks $weeks
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Business $business
 * @property-read Practitioner $practitioner
 */
#[Fillable(['starts_on', 'ends_on', 'cycle_starts_on', 'weeks'])]
class WorkingPattern extends Model
{
    /** @use HasFactory<WorkingPatternFactory> */
    use BelongsToBusiness, HasFactory;

    /**
     * @return BelongsTo<Practitioner, $this>
     */
    public function practitioner(): BelongsTo
    {
        return $this->belongsTo(Practitioner::class);
    }

    /**
     * The pattern as the hours code reads it.
     */
    public function toRepeatingPattern(): RepeatingPattern
    {
        return new RepeatingPattern($this->starts_on, $this->ends_on, $this->cycle_starts_on, $this->weeks);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'cycle_starts_on' => 'date',
            'weeks' => AsPatternWeeks::class,
        ];
    }
}
