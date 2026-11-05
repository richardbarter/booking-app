<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Carbon\CarbonImmutable;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Someone who has booked with a business, with no login. They're identified
 * within the business by email, stored trimmed and in lower case. The name
 * and phone are as given with the first booking; later bookings never change
 * them, and record their own.
 *
 * @property int $id
 * @property int $business_id
 * @property string $name
 * @property string $email
 * @property string $phone
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Business $business
 * @property-read Collection<int, Booking> $bookings
 */
#[Fillable(['name', 'email', 'phone'])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use BelongsToBusiness, HasFactory;

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
