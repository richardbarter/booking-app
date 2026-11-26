<?php

namespace Tests\Feature\Actions\Playground;

use App\Actions\Playground\StartSandbox;
use App\Models\Business;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class StartSandboxTest extends TestCase
{
    use RefreshDatabase;

    private const array SAMPLE_TABLES = ['businesses', 'practitioners', 'services', 'working_patterns', 'edited_days', 'time_off'];

    public function test_the_sandbox_lasts_as_long_as_the_session(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-25 10:00', 'UTC'));
        config(['session.lifetime' => 30]);

        $sandbox = app(StartSandbox::class)->handle();

        $this->assertTrue($sandbox->isSandbox());
        $this->assertSame('2026-11-25T10:30:00+00:00', $sandbox->fresh()?->sandbox_expires_at?->toIso8601String());
    }

    public function test_leaves_nothing_behind_if_setting_the_expiry_fails(): void
    {
        Business::updating(fn (): never => throw new RuntimeException('Saving the expiry failed.'));

        try {
            app(StartSandbox::class)->handle();
            $this->fail('The sandbox was started although saving its expiry failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Saving the expiry failed.', $exception->getMessage());
        }

        foreach (self::SAMPLE_TABLES as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }
}
