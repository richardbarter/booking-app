<?php

namespace App\Models;

use App\Casts\AsUtcDateTime;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Models\Concerns\BelongsToBusiness;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's appointment with one practitioner for one service, stored in
 * UTC. The appointment runs from starts_at to ends_at, and the practitioner
 * is blocked from blocked_from to blocked_until, which add the service's
 * buffers. The times and price are copied when booked, and customer_name and
 * customer_phone are as given with this booking.
 *
 * @property int $id
 * @property int $business_id
 * @property int $practitioner_id
 * @property int $service_id
 * @property int $customer_id
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property CarbonImmutable $blocked_from
 * @property CarbonImmutable $blocked_until
 * @property string $customer_name
 * @property string $customer_phone
 * @property int $price_pence
 * @property BookingStatus $status
 * @property BookingSource $source
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Business $business
 * @property-read Practitioner $practitioner
 * @property-read Service $service
 * @property-read Customer $customer
 */
#[Fillable(['starts_at', 'ends_at', 'blocked_from', 'blocked_until', 'customer_name', 'customer_phone', 'price_pence', 'status', 'source'])]
class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use BelongsToBusiness, HasFactory;

    /**
     * The database's default, so a new booking has it before it's saved or
     * reloaded.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'confirmed',
    ];

    /**
     * @return BelongsTo<Practitioner, $this>
     */
    public function practitioner(): BelongsTo
    {
        return $this->belongsTo(Practitioner::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Bookings that block their time: every one that isn't cancelled. It's
     * the exclusion constraint's condition, written the same way, so its
     * index can serve queries that use this scope.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereRaw("bookings.status <> 'cancelled'");
    }

    /**
     * Confirmed bookings whose end is at or before $now. Attended isn't
     * stored, so nothing has to update bookings on a timer.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function attended(Builder $query, CarbonInterface $now): void
    {
        $query->where($query->qualifyColumn('status'), BookingStatus::Confirmed)
            ->where($query->qualifyColumn('ends_at'), '<=', $now->toImmutable()->utc()->format('Y-m-d H:i:sP'));
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
            'blocked_from' => AsUtcDateTime::class,
            'blocked_until' => AsUtcDateTime::class,
            'price_pence' => 'integer',
            'status' => BookingStatus::class,
            'source' => BookingSource::class,
        ];
    }
}
