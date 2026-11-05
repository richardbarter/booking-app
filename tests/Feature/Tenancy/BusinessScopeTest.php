<?php

namespace Tests\Feature\Tenancy;

use App\Exceptions\MissingCurrentBusiness;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Customer;
use App\Models\EditedDay;
use App\Models\Practitioner;
use App\Models\Scopes\BusinessScope;
use App\Models\Service;
use App\Models\TimeOff;
use App\Models\WorkingPattern;
use App\Services\CurrentBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BusinessScopeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{class-string<Model>}>
     */
    public static function tenantModels(): array
    {
        return [
            'practitioners' => [Practitioner::class],
            'services' => [Service::class],
            'working patterns' => [WorkingPattern::class],
            'edited days' => [EditedDay::class],
            'time off' => [TimeOff::class],
            'customers' => [Customer::class],
            'bookings' => [Booking::class],
        ];
    }

    /**
     * @param  class-string<Model>  $model
     */
    #[DataProvider('tenantModels')]
    public function test_queries_only_return_the_current_businesss_rows(string $model): void
    {
        $practitioner = $this->practitionerWithOneOfEverything();
        $this->practitionerWithOneOfEverything();

        $businessIds = app(CurrentBusiness::class)->run(
            $practitioner->business,
            fn (): array => $model::query()->pluck('business_id')->all(),
        );

        $this->assertSame([$practitioner->business_id], $businessIds);
    }

    public function test_relations_only_return_the_current_businesss_rows(): void
    {
        $practitioner = $this->practitionerWithOneOfEverything();
        $otherPractitioner = $this->practitionerWithOneOfEverything();

        $counts = app(CurrentBusiness::class)->run($practitioner->business, fn (): array => [
            'own' => [
                $practitioner->business->practitioners()->count(),
                $practitioner->business->services()->count(),
                $practitioner->workingPatterns()->count(),
                $practitioner->editedDays()->count(),
                $practitioner->timeOff()->count(),
                $practitioner->business->customers()->count(),
                $practitioner->bookings()->count(),
                $this->rowsOf($practitioner)['booking']->customer()->count(),
            ],
            'other business' => [
                $otherPractitioner->business->practitioners()->count(),
                $otherPractitioner->business->services()->count(),
                $otherPractitioner->workingPatterns()->count(),
                $otherPractitioner->editedDays()->count(),
                $otherPractitioner->timeOff()->count(),
                $otherPractitioner->business->customers()->count(),
                $otherPractitioner->bookings()->count(),
                $this->rowsOf($otherPractitioner)['booking']->customer()->count(),
            ],
        ]);

        $this->assertSame(['own' => [1, 1, 1, 1, 1, 1, 1, 1], 'other business' => [0, 0, 0, 0, 0, 0, 0, 0]], $counts);
    }

    public function test_eager_loading_only_loads_the_current_businesss_rows(): void
    {
        $practitioner = $this->practitionerWithOneOfEverything();
        $otherPractitioner = $this->practitionerWithOneOfEverything();

        $businesses = app(CurrentBusiness::class)->run(
            $practitioner->business,
            fn () => Business::with(['services', 'customers', 'practitioners.workingPatterns', 'practitioners.bookings.customer'])->get()->keyBy('id'),
        );

        $own = $businesses[$practitioner->business_id];
        $other = $businesses[$otherPractitioner->business_id];
        $this->assertCount(1, $own->services);
        $this->assertCount(1, $own->practitioners);
        $this->assertCount(1, $own->practitioners->sole()->workingPatterns);
        $this->assertCount(1, $own->customers);
        $this->assertSame($own->customers->sole()->id, $own->practitioners->sole()->bookings->sole()->customer->id);
        $this->assertCount(0, $other->services);
        $this->assertCount(0, $other->practitioners);
        $this->assertCount(0, $other->customers);
    }

    /**
     * @param  class-string<Model>  $model
     */
    #[DataProvider('tenantModels')]
    public function test_querying_with_no_current_business_throws(string $model): void
    {
        $this->expectException(MissingCurrentBusiness::class);

        $model::query()->get();
    }

    /**
     * @param  class-string<Model>  $model
     */
    #[DataProvider('tenantModels')]
    public function test_queries_without_the_scope_see_every_business(string $model): void
    {
        $first = $this->practitionerWithOneOfEverything();
        $second = $this->practitionerWithOneOfEverything();

        $businessIds = $model::withoutGlobalScope(BusinessScope::class)->orderBy('business_id')->pluck('business_id')->all();

        $this->assertSame([$first->business_id, $second->business_id], $businessIds);
    }

    public function test_businesses_can_be_queried_with_no_current_business(): void
    {
        $business = Business::factory()->create();

        $this->assertSame([$business->id], Business::query()->pluck('id')->all());
    }

    public function test_route_binding_finds_the_current_businesss_rows(): void
    {
        $practitioner = $this->practitionerWithOneOfEverything();
        $rows = $this->rowsOf($practitioner);

        $found = app(CurrentBusiness::class)->run($practitioner->business, fn (): array => [
            (new Practitioner)->resolveRouteBinding($practitioner->id)?->getKey(),
            (new Service)->resolveRouteBinding($rows['service']->id)?->getKey(),
            (new WorkingPattern)->resolveRouteBinding($rows['pattern']->id)?->getKey(),
            (new Customer)->resolveRouteBinding($rows['customer']->id)?->getKey(),
            (new Booking)->resolveRouteBinding($rows['booking']->id)?->getKey(),
        ]);

        $this->assertSame([$practitioner->id, $rows['service']->id, $rows['pattern']->id, $rows['customer']->id, $rows['booking']->id], $found);
    }

    public function test_route_binding_does_not_find_another_businesss_rows(): void
    {
        $practitioner = $this->practitionerWithOneOfEverything();
        $otherPractitioner = $this->practitionerWithOneOfEverything();
        $otherRows = $this->rowsOf($otherPractitioner);

        $found = app(CurrentBusiness::class)->run($practitioner->business, fn (): array => [
            (new Practitioner)->resolveRouteBinding($otherPractitioner->id),
            (new Service)->resolveRouteBinding($otherRows['service']->id),
            (new WorkingPattern)->resolveRouteBinding($otherRows['pattern']->id),
            (new Customer)->resolveRouteBinding($otherRows['customer']->id),
            (new Booking)->resolveRouteBinding($otherRows['booking']->id),
        ]);

        $this->assertSame([null, null, null, null, null], $found);
    }

    /**
     * A practitioner, with its own business, and one of each row that
     * belongs to them.
     */
    private function practitionerWithOneOfEverything(): Practitioner
    {
        $practitioner = Practitioner::factory()->create();
        $service = Service::factory()->for($practitioner->business)->create();
        WorkingPattern::factory()->for($practitioner)->create();
        EditedDay::factory()->for($practitioner)->create();
        TimeOff::factory()->for($practitioner)->create();
        Booking::factory()->for($practitioner)->for($service)->create();

        return $practitioner;
    }

    /**
     * @return array{service: Service, pattern: WorkingPattern, customer: Customer, booking: Booking}
     */
    private function rowsOf(Practitioner $practitioner): array
    {
        return [
            'service' => Service::withoutGlobalScope(BusinessScope::class)->where('business_id', $practitioner->business_id)->sole(),
            'pattern' => WorkingPattern::withoutGlobalScope(BusinessScope::class)->where('business_id', $practitioner->business_id)->sole(),
            'customer' => Customer::withoutGlobalScope(BusinessScope::class)->where('business_id', $practitioner->business_id)->sole(),
            'booking' => Booking::withoutGlobalScope(BusinessScope::class)->where('business_id', $practitioner->business_id)->sole(),
        ];
    }
}
