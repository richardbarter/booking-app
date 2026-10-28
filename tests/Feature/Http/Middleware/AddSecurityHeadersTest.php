<?php

namespace Tests\Feature\Http\Middleware;

use Tests\TestCase;

class AddSecurityHeadersTest extends TestCase
{
    public function test_pages_carry_the_security_headers(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }

    public function test_https_responses_carry_hsts(): void
    {
        $response = $this->get('https://localhost/');

        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_http_responses_do_not_carry_hsts(): void
    {
        $response = $this->get('http://localhost/');

        $response->assertHeaderMissing('Strict-Transport-Security');
    }
}
