<?php

namespace Tests\Feature\Database;

use App\Models\Business;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BusinessesTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_business_gets_the_default_settings_in_the_database_and_the_model(): void
    {
        $id = DB::table('businesses')->insertGetId(['name' => 'Inserted directly']);

        $stored = Business::findOrFail($id)->only(['timezone', 'slot_interval_minutes', 'min_notice_minutes', 'max_advance_days']);
        $unsaved = (new Business)->only(['timezone', 'slot_interval_minutes', 'min_notice_minutes', 'max_advance_days']);

        $this->assertSame(
            ['timezone' => 'Europe/London', 'slot_interval_minutes' => 15, 'min_notice_minutes' => 120, 'max_advance_days' => 90],
            $stored,
        );
        $this->assertSame($stored, $unsaved);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function allowedSlotIntervals(): array
    {
        return ['10' => [10], '15' => [15], '20' => [20], '30' => [30], '60' => [60]];
    }

    #[DataProvider('allowedSlotIntervals')]
    public function test_accepts_each_slot_interval(int $minutes): void
    {
        $business = Business::factory()->create(['slot_interval_minutes' => $minutes]);

        $this->assertModelExists($business);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function disallowedSlotIntervals(): array
    {
        return ['5' => [5], '25' => [25], '45' => [45]];
    }

    #[DataProvider('disallowedSlotIntervals')]
    public function test_rejects_a_slot_interval_outside_the_five_choices(int $minutes): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('"businesses_slot_interval_minutes_check"');

        Business::factory()->create(['slot_interval_minutes' => $minutes]);
    }

    /**
     * @return array<string, array{array<string, int>, string}>
     */
    public static function bookingWindowsOutOfRange(): array
    {
        return [
            'negative notice' => [['min_notice_minutes' => -1], 'businesses_min_notice_minutes_check'],
            'notice over 30 days' => [['min_notice_minutes' => 43201, 'max_advance_days' => 365], 'businesses_min_notice_minutes_check'],
            'no advance, which leaves nothing bookable' => [['max_advance_days' => 0, 'min_notice_minutes' => 0], 'businesses_booking_window_check'],
            'advance over a year' => [['max_advance_days' => 366], 'businesses_max_advance_days_check'],
            'notice as long as the advance' => [['min_notice_minutes' => 1440, 'max_advance_days' => 1], 'businesses_booking_window_check'],
        ];
    }

    /**
     * @param  array<string, int>  $settings
     */
    #[DataProvider('bookingWindowsOutOfRange')]
    public function test_rejects_a_booking_window_out_of_range(array $settings, string $constraint): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("\"{$constraint}\"");

        Business::factory()->create($settings);
    }

    /**
     * @return array<string, array{array<string, int>}>
     */
    public static function bookingWindowsAtTheirLimits(): array
    {
        return [
            'no notice, one day ahead' => [['min_notice_minutes' => 0, 'max_advance_days' => 1]],
            'notice just under the advance' => [['min_notice_minutes' => 1439, 'max_advance_days' => 1]],
            '30 days notice, a year ahead' => [['min_notice_minutes' => 43200, 'max_advance_days' => 365]],
        ];
    }

    /**
     * @param  array<string, int>  $settings
     */
    #[DataProvider('bookingWindowsAtTheirLimits')]
    public function test_accepts_a_booking_window_at_its_limits(array $settings): void
    {
        $business = Business::factory()->create($settings);

        $this->assertModelExists($business);
    }
}
