<?php

namespace App\Models;

use App\Casts\AsUtcDateTime;
use App\Models\Concerns\BelongsToBusiness;
use Carbon\CarbonImmutable;
use Database\Factories\TimeOffFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-off period when the practitioner isn't available, stored in UTC.
 * The reason is for the business only, since it could be sensitive, and is
 * never shown to customers.
 *
 * @property int $id
 * @property int $business_id
 * @property int $practitioner_id
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property string|null $reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Business $business
 * @property-read Practitioner $practitioner
 */
#[Table('time_off')]
#[Fillable(['starts_at', 'ends_at', 'reason'])]
class TimeOff extends Model
{
    /** @use HasFactory<TimeOffFactory> */
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
            'starts_at' => AsUtcDateTime::class,
            'ends_at' => AsUtcDateTime::class,
        ];
    }
}
