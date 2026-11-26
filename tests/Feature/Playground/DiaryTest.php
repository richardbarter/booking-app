<?php

namespace Tests\Feature\Playground;

use App\Models\Business;
use App\Models\Service;
use App\Services\CurrentBusiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DiaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_shows_the_sandboxs_business_and_its_active_services_in_order(): void
    {
        $this->post(route('playground.start.store'));
        $sandbox = Business::query()->sole();
        [$followUp, $assessment] = app(CurrentBusiness::class)->run($sandbox, function (): array {
            Service::query()->where('name', 'Sports massage, 90 minutes')->update(['is_active' => false]);

            return Service::query()->active()->orderBy('id')->pluck('id')->all();
        });

        $response = $this->get(route('playground.show'));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('playground/Show')
            ->where('business', [
                'name' => 'Alex Morgan Physio & Sports Massage',
                'timezone' => 'Europe/London',
                'strictness' => 50,
                'relaxationHours' => 48,
                'minNoticeMinutes' => 120,
            ])
            ->where('services', [
                ['id' => $followUp, 'name' => 'Physio follow-up', 'durationMinutes' => 30],
                ['id' => $assessment, 'name' => 'Physio assessment', 'durationMinutes' => 60],
            ]));
    }
}
