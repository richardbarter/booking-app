<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\BusinessFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
 * @property int $id
 * @property string $name
 * @property string $timezone
 * @property int $slot_interval_minutes
 * @property int $min_notice_minutes
 * @property int $max_advance_days
 * @property int $strictness
 * @property int $relaxation_hours
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
        ];
    }
}
