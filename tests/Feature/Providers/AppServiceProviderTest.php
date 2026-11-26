<?php

namespace Tests\Feature\Providers;

use App\Models\Business;
use App\Models\Service;
use App\Providers\AppServiceProvider;
use App\Services\CurrentBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Nightwatch\Facades\Nightwatch;
use Tests\TestCase;

class AppServiceProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_lazy_loading_a_relation_of_one_of_several_models_throws(): void
    {
        $business = Business::factory()->create();
        Service::factory()->count(2)->for($business)->create();
        $services = app(CurrentBusiness::class)->run($business, fn () => Service::query()->get());

        $this->expectException(LazyLoadingViolationException::class);
        $this->expectExceptionMessage('[business]');

        $services->firstOrFail()->business;
    }

    public function test_lazy_loading_is_allowed_in_production(): void
    {
        app()->detectEnvironment(fn (): string => 'production');

        (new AppServiceProvider(app()))->boot();

        $this->assertFalse(Model::preventsLazyLoading());
    }

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
