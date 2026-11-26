<?php

namespace Tests\Feature\Models;

use App\Models\Business;
use Tests\TestCase;

class BusinessTest extends TestCase
{
    public function test_gives_the_engine_each_of_its_booking_settings(): void
    {
        $business = Business::factory()->make([
            'timezone' => 'Pacific/Auckland',
            'slot_interval_minutes' => 20,
            'min_notice_minutes' => 45,
            'max_advance_days' => 30,
            'strictness' => 65,
            'relaxation_hours' => 24,
        ]);

        $settings = $business->bookingSettings();

        $this->assertSame(['Pacific/Auckland', 20, 45, 30, 65, 24], [
            $settings->timezone->getName(),
            $settings->slotIntervalMinutes,
            $settings->minNoticeMinutes,
            $settings->maxAdvanceDays,
            $settings->strictness,
            $settings->relaxationHours,
        ]);
    }
}
