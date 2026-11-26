<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Nightwatch\Facades\Nightwatch;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureMonitoring();
        $this->configureRateLimiting();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Model::preventLazyLoading(! app()->isProduction());

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Keep Fly's health checks out of Nightwatch.
     *
     * Fly calls /up every 30 seconds while the Machine is running, and
     * recording each one would use up Nightwatch's event allowance for nothing.
     */
    protected function configureMonitoring(): void
    {
        Event::listen(DiagnosingHealth::class, fn () => Nightwatch::dontSample());
    }

    /**
     * The playground's limits (08). Laravel checks every limit in a list
     * before counting against any, so a request one limit refuses doesn't
     * use up the others. The limiters use the cache, which is the database
     * in production, touched only by visitors' requests.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('sandboxes', fn (Request $request): array => [
            Limit::perHour(config()->integer('playground.sandboxes_per_ip'))
                ->by('ip:'.$request->ip())
                ->response($this->backWithToast(
                    __('Too many playgrounds have been started from your connection in the last hour. Please try again later.'),
                    'playground.start',
                )),
            Limit::perHour(config()->integer('playground.sandboxes_in_all'))
                ->by('all')
                ->response($this->backWithToast(
                    __('The playground is busy. Please try again later.'),
                    'playground.start',
                )),
        ]);
    }

    /**
     * A refused request goes back to the page it came from, with a toast,
     * rather than to a bare 429 page. 303 makes the browser follow it with a
     * GET, whatever the request's method.
     *
     * @return Closure(Request, array<string, string>): RedirectResponse
     */
    private function backWithToast(string $message, string $fallbackRoute): Closure
    {
        return function (Request $request, array $headers) use ($message, $fallbackRoute): RedirectResponse {
            Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

            return back(303, $headers, route($fallbackRoute));
        };
    }
}
