<?php

namespace Tests\Feature\Providers;

use Laravel\Nightwatch\Facades\Nightwatch;
use Tests\TestCase;

class AppServiceProviderTest extends TestCase
{
    public function test_health_checks_are_left_out_of_nightwatch(): void
    {
        $response = $this->get('/up');

        $response->assertOk();
        $this->assertFalse(Nightwatch::sampling());
    }

    public function test_pages_are_still_recorded_in_nightwatch(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        $this->assertTrue(Nightwatch::sampling());
    }
}
