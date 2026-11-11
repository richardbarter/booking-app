<?php

namespace Tests\Feature\Database;

use App\Models\Service;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ServicesTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_service_has_no_buffers_is_active_and_keeps_full_room_in_the_database_and_the_model(): void
    {
        $service = Service::factory()->create();

        $unsaved = (new Service)->only(['buffer_before_minutes', 'buffer_after_minutes', 'is_active', 'room_weight']);
        $stored = $service->fresh()?->only(['buffer_before_minutes', 'buffer_after_minutes', 'is_active', 'room_weight']);

        $this->assertSame(['buffer_before_minutes' => 0, 'buffer_after_minutes' => 0, 'is_active' => true, 'room_weight' => 10], $stored);
        $this->assertSame($stored, $unsaved);
    }

    /**
     * @return array<string, array{array<string, int>, string}>
     */
    public static function valuesOutOfRange(): array
    {
        return [
            'length under 5' => [['duration_minutes' => 0], 'services_duration_minutes_check'],
            'length over 8 hours' => [['duration_minutes' => 485], 'services_duration_minutes_check'],
            'length off the 5-minute step' => [['duration_minutes' => 62], 'services_duration_minutes_check'],
            'negative buffer before' => [['buffer_before_minutes' => -5], 'services_buffer_before_minutes_check'],
            'buffer before over 2 hours' => [['buffer_before_minutes' => 125], 'services_buffer_before_minutes_check'],
            'buffer before off the step' => [['buffer_before_minutes' => 7], 'services_buffer_before_minutes_check'],
            'negative buffer after' => [['buffer_after_minutes' => -5], 'services_buffer_after_minutes_check'],
            'buffer after over 2 hours' => [['buffer_after_minutes' => 125], 'services_buffer_after_minutes_check'],
            'buffer after off the step' => [['buffer_after_minutes' => 7], 'services_buffer_after_minutes_check'],
            'negative price' => [['price_pence' => -1], 'services_price_pence_check'],
            'negative room weight' => [['room_weight' => -1], 'services_room_weight_check'],
            'room weight over 10' => [['room_weight' => 11], 'services_room_weight_check'],
        ];
    }

    /**
     * @param  array<string, int>  $values
     */
    #[DataProvider('valuesOutOfRange')]
    public function test_rejects_a_length_buffer_price_or_room_weight_out_of_range(array $values, string $constraint): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("\"{$constraint}\"");

        Service::factory()->create($values);
    }

    /**
     * @return array<string, array{array<string, int>}>
     */
    public static function valuesAtTheirLimits(): array
    {
        return [
            'shortest, free, no buffers, no room kept' => [['duration_minutes' => 5, 'price_pence' => 0, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0, 'room_weight' => 0]],
            'longest, with the longest buffers and full room kept' => [['duration_minutes' => 480, 'buffer_before_minutes' => 120, 'buffer_after_minutes' => 120, 'room_weight' => 10]],
        ];
    }

    /**
     * @param  array<string, int>  $values
     */
    #[DataProvider('valuesAtTheirLimits')]
    public function test_accepts_values_at_their_limits(array $values): void
    {
        $service = Service::factory()->create($values);

        $this->assertModelExists($service);
    }
}
