<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Carbon\CarbonImmutable;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Something a customer can book. In v1 the practitioner offers every active
 * service, at the service's length, buffers and price.
 *
 * @property int $id
 * @property int $business_id
 * @property string $name
 * @property int $duration_minutes
 * @property int $buffer_before_minutes
 * @property int $buffer_after_minutes
 * @property int $price_pence
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Business $business
 */
#[Fillable(['name', 'duration_minutes', 'buffer_before_minutes', 'buffer_after_minutes', 'price_pence', 'is_active'])]
class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use BelongsToBusiness, HasFactory;

    /**
     * The database's defaults, so a new service has them before it's saved
     * or reloaded.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'is_active' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'buffer_before_minutes' => 'integer',
            'buffer_after_minutes' => 'integer',
            'price_pence' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
