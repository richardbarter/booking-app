<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Carbon\CarbonImmutable;
use Database\Factories\PractitionerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The person whose diary is booked. Hours, time off and bookings belong to
 * the practitioner. Each business has exactly one in v1.
 *
 * @property int $id
 * @property int $business_id
 * @property string $name
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Business $business
 * @property-read Collection<int, WorkingPattern> $workingPatterns
 * @property-read Collection<int, EditedDay> $editedDays
 * @property-read Collection<int, TimeOff> $timeOff
 * @property-read Collection<int, Booking> $bookings
 */
#[Fillable(['name'])]
class Practitioner extends Model
{
    /** @use HasFactory<PractitionerFactory> */
    use BelongsToBusiness, HasFactory;

    /**
     * @return HasMany<WorkingPattern, $this>
     */
    public function workingPatterns(): HasMany
    {
        return $this->hasMany(WorkingPattern::class);
    }

    /**
     * @return HasMany<EditedDay, $this>
     */
    public function editedDays(): HasMany
    {
        return $this->hasMany(EditedDay::class);
    }

    /**
     * @return HasMany<TimeOff, $this>
     */
    public function timeOff(): HasMany
    {
        return $this->hasMany(TimeOff::class);
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
