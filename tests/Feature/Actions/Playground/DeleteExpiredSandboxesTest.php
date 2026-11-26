<?php

namespace Tests\Feature\Actions\Playground;

use App\Actions\CreateSampleBusiness;
use App\Actions\Playground\DeleteExpiredSandboxes;
use App\Actions\Playground\StartSandbox;
use App\Models\Business;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeleteExpiredSandboxesTest extends TestCase
{
    use RefreshDatabase;

    private const array SAMPLE_TABLES = ['practitioners', 'services', 'working_patterns', 'edited_days', 'time_off'];

    public function test_deletes_expired_sandboxes_with_everything_they_own_and_leaves_live_ones_and_real_businesses_alone(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-25 10:00', 'UTC'));
        $expired = $this->sandboxEndingAt('2026-11-25 09:59');
        $endingNow = $this->sandboxEndingAt('2026-11-25 10:00');
        $live = $this->sandboxEndingAt('2026-11-25 10:01');
        $real = app(CreateSampleBusiness::class)->handle();

        $deleted = app(DeleteExpiredSandboxes::class)->handle();

        $this->assertSame(2, $deleted);
        $this->assertModelMissing($expired);
        $this->assertModelMissing($endingNow);
        $this->assertModelExists($live);
        $this->assertModelExists($real);
        foreach (self::SAMPLE_TABLES as $table) {
            $this->assertSame(0, DB::table($table)->whereIn('business_id', [$expired->id, $endingNow->id])->count(), "{$table} kept an expired sandbox's rows.");
            $this->assertSame(2, DB::table($table)->whereIn('business_id', [$live->id, $real->id])->distinct()->count('business_id'), "{$table} lost a kept business's rows.");
        }
    }

    public function test_deletes_at_most_50_at_once_the_oldest_first(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-25 10:00', 'UTC'));
        $newest = Business::factory()->sandbox(now()->subMinute())->create();
        for ($minutesAgo = 2; $minutesAgo <= 51; $minutesAgo++) {
            Business::factory()->sandbox(now()->subMinutes($minutesAgo))->create();
        }

        $deleted = app(DeleteExpiredSandboxes::class)->handle();

        $this->assertSame(50, $deleted);
        $this->assertSame([$newest->id], Business::query()->pluck('id')->all());
    }

    private function sandboxEndingAt(string $expiresAt): Business
    {
        $sandbox = app(StartSandbox::class)->handle();
        $sandbox->sandbox_expires_at = CarbonImmutable::parse($expiresAt, 'UTC');
        $sandbox->save();

        return $sandbox;
    }
}
