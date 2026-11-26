<?php

namespace Tests\Feature\Playground;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * The limits on starting sandboxes (08), set low here. Each start comes
 * from a new session, as from a new visitor, so it makes a sandbox.
 */
class SandboxRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_start_over_the_limit_for_one_address_is_refused_with_a_toast_and_another_address_is_not(): void
    {
        config(['playground.sandboxes_per_ip' => 2, 'playground.sandboxes_in_all' => 10]);
        $this->startFrom('203.0.113.1');
        $this->startFrom('203.0.113.1');

        $refused = $this->startFrom('203.0.113.1');

        $refused->assertStatus(303);
        $refused->assertRedirectToRoute('playground.start');
        $refused->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Too many playgrounds have been started from your connection in the last hour. Please try again later.']);
        $this->assertDatabaseCount('businesses', 2);
        $this->startFrom('198.51.100.7')->assertRedirectToRoute('playground.show');
        $this->assertDatabaseCount('businesses', 3);
    }

    public function test_the_limit_for_all_visitors_applies_across_addresses(): void
    {
        config(['playground.sandboxes_per_ip' => 10, 'playground.sandboxes_in_all' => 2]);
        $this->startFrom('203.0.113.1');
        $this->startFrom('203.0.113.2');

        $refused = $this->startFrom('203.0.113.3');

        $refused->assertStatus(303);
        $refused->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'The playground is busy. Please try again later.']);
        $this->assertDatabaseCount('businesses', 2);
    }

    public function test_a_start_refused_for_its_address_does_not_count_towards_the_limit_for_all_visitors(): void
    {
        config(['playground.sandboxes_per_ip' => 1, 'playground.sandboxes_in_all' => 2]);
        $this->startFrom('203.0.113.1');
        $this->startFrom('203.0.113.1')->assertStatus(303);

        $response = $this->startFrom('198.51.100.7');

        $response->assertRedirectToRoute('playground.show');
        $this->assertDatabaseCount('businesses', 2);
    }

    /**
     * @return TestResponse<Response>
     */
    private function startFrom(string $ip): TestResponse
    {
        $this->flushSession();

        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->from(route('playground.start'))
            ->post(route('playground.start.store'));
    }
}
