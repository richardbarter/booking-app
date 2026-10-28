<?php

namespace Tests\Feature\Http;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_pages_carry_the_security_headers(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('X-Frame-Options', 'deny');
        $response->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
        $response->assertHeaderMissing('Content-Security-Policy');
    }

    public function test_pages_turn_off_camera_microphone_and_location(): void
    {
        $response = $this->get(route('home'));

        $permissionsPolicy = $response->headers->get('Permissions-Policy');

        $this->assertNotNull($permissionsPolicy);
        $this->assertStringContainsString('camera=()', $permissionsPolicy);
        $this->assertStringContainsString('microphone=()', $permissionsPolicy);
        $this->assertStringContainsString('geolocation=()', $permissionsPolicy);
    }

    public function test_https_responses_carry_hsts(): void
    {
        $response = $this->get('https://localhost/');

        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }
}
