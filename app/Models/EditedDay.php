<?php

namespace App\Models;

use App\Casts\AsDayHours;
use App\Models\Concerns\BelongsToBusiness;
use App\Scheduling\Hours\DayHours;
use Carbon\CarbonImmutable;
use Database\Factories\EditedDayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A date whose hours were entered by hand, replacing the pattern's for that
 * date. Its hours may be empty, for a day off.
 *
 * @property int $id
 * @property int $business_id
 * @property int $practitioner_id
 * @property CarbonImmutable $date
 * @property DayHours $hours
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Business $business
 * @property-read Practitioner $practitioner
 */
#[Fillable(['date', 'hours'])]
class EditedDay extends Model
{
    /** @use HasFactory<EditedDayFactory> */
    use BelongsToBusiness, HasFactory;

    /**
     * @return BelongsTo<Practitioner, $this>
     */
    public function practitioner(): BelongsTo
    {
        return $this->belongsTo(Practitioner::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'hours' => AsDayHours::class,
        ];
    }
}
