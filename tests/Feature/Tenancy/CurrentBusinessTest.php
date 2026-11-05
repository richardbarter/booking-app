<?php

namespace Tests\Feature\Tenancy;

use App\Exceptions\MissingCurrentBusiness;
use App\Models\Business;
use App\Services\CurrentBusiness;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class CurrentBusinessTest extends TestCase
{
    public function test_get_throws_when_no_business_is_set(): void
    {
        $currentBusiness = new CurrentBusiness;

        $this->assertFalse($currentBusiness->has());
        $this->expectException(MissingCurrentBusiness::class);

        $currentBusiness->get();
    }

    public function test_set_makes_a_business_current(): void
    {
        $business = Business::factory()->make(['id' => 7]);
        $currentBusiness = new CurrentBusiness;

        $currentBusiness->set($business);

        $this->assertTrue($currentBusiness->has());
        $this->assertSame($business, $currentBusiness->get());
        $this->assertSame(7, $currentBusiness->id());
    }

    public function test_forget_leaves_no_current_business(): void
    {
        $currentBusiness = new CurrentBusiness;
        $currentBusiness->set(Business::factory()->make());

        $currentBusiness->forget();

        $this->assertFalse($currentBusiness->has());
    }

    public function test_ensure_is_accepts_the_current_business_however_it_was_loaded(): void
    {
        $currentBusiness = new CurrentBusiness;
        $currentBusiness->set(Business::factory()->make(['id' => 7]));

        $currentBusiness->ensureIs(Business::factory()->make(['id' => 7]));

        $this->assertSame(7, $currentBusiness->id());
    }

    public function test_ensure_is_throws_for_a_business_that_is_not_the_current_one(): void
    {
        $currentBusiness = new CurrentBusiness;
        $currentBusiness->set(Business::factory()->make(['id' => 7]));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Work was asked for business 8, but the current business is 7.');

        $currentBusiness->ensureIs(Business::factory()->make(['id' => 8]));
    }

    public function test_ensure_is_throws_when_no_business_is_set(): void
    {
        $this->expectException(MissingCurrentBusiness::class);

        (new CurrentBusiness)->ensureIs(Business::factory()->make(['id' => 7]));
    }

    public function test_run_makes_the_business_current_for_the_callback_and_returns_its_result(): void
    {
        $business = Business::factory()->make();
        $currentBusiness = new CurrentBusiness;

        $seen = $currentBusiness->run($business, fn (): Business => $currentBusiness->get());

        $this->assertSame($business, $seen);
        $this->assertFalse($currentBusiness->has());
    }

    public function test_run_restores_the_previous_business_afterwards(): void
    {
        $previous = Business::factory()->make();
        $currentBusiness = new CurrentBusiness;
        $currentBusiness->set($previous);

        $currentBusiness->run(Business::factory()->make(), fn (): null => null);

        $this->assertSame($previous, $currentBusiness->get());
    }

    public function test_run_restores_the_previous_business_when_the_callback_throws(): void
    {
        $previous = Business::factory()->make();
        $currentBusiness = new CurrentBusiness;
        $currentBusiness->set($previous);

        try {
            $currentBusiness->run(Business::factory()->make(), fn () => throw new RuntimeException('Callback failed'));
            $this->fail('The callback\'s exception should reach the caller.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Callback failed', $exception->getMessage());
        }

        $this->assertSame($previous, $currentBusiness->get());
    }

    public function test_run_leaves_no_current_business_when_there_was_none_and_the_callback_throws(): void
    {
        $currentBusiness = new CurrentBusiness;

        try {
            $currentBusiness->run(Business::factory()->make(), fn () => throw new RuntimeException('Callback failed'));
        } catch (RuntimeException) {
            // Expected: the callback's exception reaches the caller.
        }

        $this->assertFalse($currentBusiness->has());
    }

    public function test_the_container_makes_a_new_one_for_each_request_or_job(): void
    {
        $currentBusiness = app(CurrentBusiness::class);
        $currentBusiness->set(Business::factory()->make());

        $sameRequest = app(CurrentBusiness::class);
        app()->forgetScopedInstances();
        $nextRequest = app(CurrentBusiness::class);

        $this->assertSame($currentBusiness, $sameRequest);
        $this->assertNotSame($currentBusiness, $nextRequest);
        $this->assertFalse($nextRequest->has());
    }
}
