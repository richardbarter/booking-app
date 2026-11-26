<?php

namespace Tests\Feature\Playground;

use App\Http\Middleware\UseSandbox;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Session\Middleware\StartSession;
use Laravel\Nightwatch\Http\Middleware\Sample;
use Tests\TestCase;

/**
 * The order the playground's middleware runs in (bootstrap/app.php).
 */
class PlaygroundRoutesTest extends TestCase
{
    public function test_every_route_samples_one_request_in_ten_before_a_limiter_can_refuse_it(): void
    {
        foreach (['playground.start', 'playground.start.store', 'playground.show'] as $name) {
            $this->assertContains(Sample::rate(0.1), $this->middlewareOf($name), "{$name} isn't sampled.");
        }

        $start = $this->middlewareOf('playground.start.store');
        $this->assertLessThan(array_search(ThrottleRequests::class.':sandboxes', $start, true), array_search(Sample::rate(0.1), $start, true));
    }

    public function test_the_sandbox_is_the_current_business_after_the_session_starts_and_before_route_model_binding(): void
    {
        $show = $this->middlewareOf('playground.show');

        $this->assertLessThan(array_search(UseSandbox::class, $show, true), array_search(StartSession::class, $show, true));
        $this->assertLessThan(array_search(SubstituteBindings::class, $show, true), array_search(UseSandbox::class, $show, true));
    }

    /**
     * The route's middleware in the order it runs. The HTTP kernel gives
     * the router its groups and priorities when it's made.
     *
     * @return list<string>
     */
    private function middlewareOf(string $routeName): array
    {
        app(Kernel::class);
        $router = app('router');

        $route = $router->getRoutes()->getByName($routeName);
        $this->assertNotNull($route);

        return array_values(array_filter($router->gatherRouteMiddleware($route), is_string(...)));
    }
}
