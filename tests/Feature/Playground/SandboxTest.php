<?php

namespace Tests\Feature\Playground;

use App\Actions\Playground\StartSandbox;
use App\Models\Business;
use App\Services\Playground\SandboxSession;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SandboxTest extends TestCase
{
    use RefreshDatabase;

    private const string ENDED = 'Your playground ended after 2 hours without a visit. Start a new one to carry on.';

    public function test_the_diary_without_a_sandbox_redirects_to_the_start_page_and_makes_no_business(): void
    {
        $response = $this->get(route('playground.show'));

        $response->assertRedirectToRoute('playground.start');
        $response->assertInertiaFlashMissing('toast');
        $this->assertDatabaseCount('businesses', 0);
    }

    public function test_the_start_page_says_how_long_a_playground_lasts_and_makes_no_business(): void
    {
        $response = $this->get(route('playground.start'));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('playground/Start')
            ->where('lifetime', '2 hours'));
        $this->assertDatabaseCount('businesses', 0);
    }

    public function test_starting_makes_a_sandbox_of_the_sample_business_that_expires_in_120_minutes(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-25 10:00', 'Europe/London'));

        $response = $this->post(route('playground.start.store'));

        $response->assertRedirectToRoute('playground.show');
        $sandbox = Business::query()->sole();
        $this->assertSame('Alex Morgan Physio & Sports Massage', $sandbox->name);
        $this->assertSame('2026-11-25T12:00:00+00:00', $sandbox->sandbox_expires_at?->toIso8601String());
        $this->assertSame(1, DB::table('practitioners')->where('business_id', $sandbox->id)->count());
        $this->assertSame(3, DB::table('services')->where('business_id', $sandbox->id)->count());
        $this->get(route('playground.show'))->assertInertia(fn (Assert $page) => $page->component('playground/Show'));
    }

    public function test_with_a_live_sandbox_both_start_routes_go_to_the_diary_and_make_no_more(): void
    {
        $this->post(route('playground.start.store'));

        $this->get(route('playground.start'))->assertRedirectToRoute('playground.show');
        $this->post(route('playground.start.store'))->assertRedirectToRoute('playground.show');

        $this->assertDatabaseCount('businesses', 1);
    }

    public function test_each_playground_request_moves_the_expiry_forward(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-25 10:00', 'UTC'));
        $this->post(route('playground.start.store'));
        $this->travelTo(CarbonImmutable::parse('2026-11-25 11:59', 'UTC'));

        $this->get(route('playground.show'))->assertOk();

        $this->assertSame('2026-11-25T13:59:00+00:00', Business::query()->sole()->sandbox_expires_at?->toIso8601String());
    }

    public function test_a_sandbox_at_its_expiry_is_refused_not_revived_and_the_start_page_says_why(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-25 10:00', 'UTC'));
        $this->post(route('playground.start.store'));
        $this->travelTo(CarbonImmutable::parse('2026-11-25 12:00', 'UTC'));

        $response = $this->get(route('playground.show'));

        $response->assertRedirectToRoute('playground.start');
        $this->assertSame('2026-11-25T12:00:00+00:00', Business::query()->sole()->sandbox_expires_at?->toIso8601String());
        $this->get(route('playground.start'))->assertInertia(fn (Assert $page) => $page
            ->component('playground/Start')
            ->hasFlash('toast', ['type' => 'info', 'message' => self::ENDED]));
        $this->get(route('playground.show'))->assertRedirectToRoute('playground.start')->assertInertiaFlashMissing('toast');
    }

    public function test_an_open_page_whose_session_has_gone_is_told_the_playground_ended(): void
    {
        $response = $this->get(route('playground.show'), ['X-Inertia' => 'true']);

        $response->assertRedirectToRoute('playground.start');
        $response->assertInertiaFlash('toast', ['type' => 'info', 'message' => self::ENDED]);
    }

    public function test_two_sessions_get_two_sandboxes_and_each_diary_shows_its_own_services(): void
    {
        $this->post(route('playground.start.store'));
        $firstServices = $this->get(route('playground.show'))->inertiaProps('services');
        $this->flushSession();

        $this->post(route('playground.start.store'));
        $secondServices = $this->get(route('playground.show'))->inertiaProps('services');

        [$first, $second] = Business::query()->orderBy('id')->get()->all();
        $this->assertSame($this->serviceIdsOf($first), array_column($firstServices, 'id'));
        $this->assertSame($this->serviceIdsOf($second), array_column($secondServices, 'id'));
        $this->assertNotSame($first->id, $second->id);
    }

    public function test_a_session_holding_a_real_businesss_id_is_sent_to_the_start_page_and_the_business_is_left_alone(): void
    {
        $real = Business::factory()->create();
        app(SandboxSession::class)->remember($real);

        $response = $this->get(route('playground.show'));

        $response->assertRedirectToRoute('playground.start');
        $this->assertSame($real->updated_at?->toIso8601String(), $real->fresh()?->updated_at?->toIso8601String());
        $this->assertNull($real->fresh()?->sandbox_expires_at);
    }

    public function test_starting_deletes_expired_sandboxes(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-25 10:00', 'UTC'));
        $expired = app(StartSandbox::class)->handle();
        $this->travelTo(CarbonImmutable::parse('2026-11-25 12:00', 'UTC'));

        $this->post(route('playground.start.store'))->assertRedirectToRoute('playground.show');

        $this->assertModelMissing($expired);
        $this->assertDatabaseMissing('practitioners', ['business_id' => $expired->id]);
        $this->assertDatabaseCount('businesses', 1);
    }

    /**
     * @return list<int>
     */
    private function serviceIdsOf(Business $business): array
    {
        return DB::table('services')->where('business_id', $business->id)->orderBy('id')->pluck('id')->all();
    }
}
