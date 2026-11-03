<?php

namespace Tests\Feature\Tenancy;

use App\Models\Business;
use App\Models\Service;
use App\Services\CurrentBusiness;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BelongsToBusinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_row_gets_the_current_businesss_id(): void
    {
        $business = Business::factory()->create();

        $service = app(CurrentBusiness::class)->run($business, fn (): Service => Service::create($this->serviceDetails()));

        $this->assertSame($business->id, $service->business_id);
        $this->assertDatabaseHas('services', ['id' => $service->id, 'business_id' => $business->id]);
    }

    public function test_a_new_row_keeps_the_business_it_was_given(): void
    {
        $currentBusiness = Business::factory()->create();
        $otherBusiness = Business::factory()->create();

        $service = app(CurrentBusiness::class)->run(
            $currentBusiness,
            fn (): Service => $otherBusiness->services()->create($this->serviceDetails()),
        );

        $this->assertDatabaseHas('services', ['id' => $service->id, 'business_id' => $otherBusiness->id]);
    }

    public function test_business_id_cannot_be_mass_assigned(): void
    {
        $currentBusiness = Business::factory()->create();
        $otherBusiness = Business::factory()->create();

        $service = app(CurrentBusiness::class)->run(
            $currentBusiness,
            fn (): Service => Service::create($this->serviceDetails() + ['business_id' => $otherBusiness->id]),
        );

        $this->assertDatabaseHas('services', ['id' => $service->id, 'business_id' => $currentBusiness->id]);
    }

    public function test_a_new_row_with_no_business_and_none_current_is_refused(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('null value in column "business_id"');

        Service::create($this->serviceDetails());
    }

    public function test_business_gives_the_rows_business(): void
    {
        $service = Service::factory()->create();

        $this->assertTrue($service->business->is(Business::find($service->business_id)));
    }

    /**
     * @return array{name: string, duration_minutes: int, price_pence: int}
     */
    private function serviceDetails(): array
    {
        return ['name' => 'Assessment', 'duration_minutes' => 60, 'price_pence' => 7000];
    }
}
