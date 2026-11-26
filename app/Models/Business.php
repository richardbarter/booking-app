<?php

namespace App\Models;

use App\Casts\AsUtcDateTime;
use App\Scheduling\BookingSettings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\BusinessFactory;
use DateTimeZone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The tenant: everything else belongs to a business. It isn't scoped itself.
 * Strictness, from 0 to 100, says how tightly its diary is packed, and the
 * relaxation window is how many hours before each time strictness stops
 * applying (07).
 *
 * A playground sandbox (08) is a business with an expiry, which is null for
 * every real business. It isn't fillable: only StartSandbox sets it.
 *
 * @property int $id
 * @property string $name
 * @property string $timezone
 * @property int $slot_interval_minutes
 * @property int $min_notice_minutes
 * @property int $max_advance_days
 * @property int $strictness
 * @property int $relaxation_hours
 * @property CarbonImmutable|null $sandbox_expires_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, Practitioner> $practitioners
 * @property-read Collection<int, Service> $services
 * @property-read Collection<int, Customer> $customers
 */
#[Fillable(['name', 'timezone', 'slot_interval_minutes', 'min_notice_minutes', 'max_advance_days', 'strictness', 'relaxation_hours'])]
class Business extends Model
{
    /** @use HasFactory<BusinessFactory> */
    use HasFactory;

    /**
     * The database's defaults, so a new business has them before it's saved
     * or reloaded.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'timezone' => 'Europe/London',
        'slot_interval_minutes' => 15,
        'min_notice_minutes' => 120,
        'max_advance_days' => 90,
        'strictness' => 50,
        'relaxation_hours' => 48,
    ];

    /**
     * The booking settings as the engine reads them.
     */
    public function bookingSettings(): BookingSettings
    {
        return new BookingSettings(
            new DateTimeZone($this->timezone),
            $this->slot_interval_minutes,
            $this->min_notice_minutes,
            $this->max_advance_days,
            $this->strictness,
            $this->relaxation_hours,
        );
    }

    public function isSandbox(): bool
    {
        return $this->sandbox_expires_at !== null;
    }

    /**
     * Sandboxes whose expiry is after $now. A real business, with no expiry,
     * never matches.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function liveSandbox(Builder $query, CarbonInterface $now): void
    {
        $query->where($query->qualifyColumn('sandbox_expires_at'), '>', self::boundInstant($now));
    }

    /**
     * Sandboxes whose expiry is at or before $now. A real business, with no
     * expiry, never matches.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function expiredSandbox(Builder $query, CarbonInterface $now): void
    {
        $query->where($query->qualifyColumn('sandbox_expires_at'), '<=', self::boundInstant($now));
    }

    /**
     * A business has exactly one practitioner in v1, which code gets with
     * $business->practitioners()->sole(). It's a has-many so staff can come
     * after v1 without changing it.
     *
     * @return HasMany<Practitioner, $this>
     */
    public function practitioners(): HasMany
    {
        return $this->hasMany(Practitioner::class);
    }

    /**
     * @return HasMany<Service, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /**
     * @return HasMany<Customer, $this>
     */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    /**
     * An instant formatted with its offset, as AsUtcDateTime writes it, so a
     * query doesn't depend on the connection's time zone.
     */
    private static function boundInstant(CarbonInterface $instant): string
    {
        return $instant->toImmutable()->utc()->format('Y-m-d H:i:sP');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'slot_interval_minutes' => 'integer',
            'min_notice_minutes' => 'integer',
            'max_advance_days' => 'integer',
            'strictness' => 'integer',
            'relaxation_hours' => 'integer',
            'sandbox_expires_at' => AsUtcDateTime::class,
        ];
    }
}
