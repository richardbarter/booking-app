<?php

namespace Tests\Feature\Actions;

use App\Actions\CreateSampleBusiness;
use App\Models\Business;
use App\Models\EditedDay;
use App\Models\Practitioner;
use App\Models\Service;
use App\Models\TimeOff;
use App\Models\WorkingPattern;
use App\Scheduling\Hours\WorkingHours;
use App\Services\CurrentBusiness;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CreateSampleBusinessTest extends TestCase
{
    use RefreshDatabase;

    private const array SPLIT_DAY = [['09:00', '13:00'], ['14:00', '17:30']];

    public function test_creates_the_business_with_one_practitioner_and_three_services(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00', 'Europe/London'));

        $business = app(CreateSampleBusiness::class)->handle();

        [$practitioners, $services] = app(CurrentBusiness::class)->run($business, fn (): array => [
            Practitioner::query()->pluck('name')->all(),
            Service::query()->orderBy('id')->get()
                ->map(fn (Service $service): array => $service->only(['name', 'duration_minutes', 'buffer_before_minutes', 'buffer_after_minutes', 'price_pence', 'is_active', 'room_weight']))
                ->all(),
        ]);
        $this->assertSame(
            ['name' => 'Alex Morgan Physio & Sports Massage', 'timezone' => 'Europe/London', 'slot_interval_minutes' => 15, 'min_notice_minutes' => 120, 'max_advance_days' => 90],
            $business->fresh()?->only(['name', 'timezone', 'slot_interval_minutes', 'min_notice_minutes', 'max_advance_days']),
        );
        $this->assertSame(['Alex Morgan'], $practitioners);
        $this->assertSame([
            ['name' => 'Physio follow-up', 'duration_minutes' => 30, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0, 'price_pence' => 5000, 'is_active' => true, 'room_weight' => 10],
            ['name' => 'Physio assessment', 'duration_minutes' => 60, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0, 'price_pence' => 7000, 'is_active' => true, 'room_weight' => 10],
            ['name' => 'Sports massage, 90 minutes', 'duration_minutes' => 90, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0, 'price_pence' => 7500, 'is_active' => true, 'room_weight' => 5],
        ], $services);
    }

    public function test_gives_the_fortnightly_hours_and_the_edited_wednesday(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00', 'Europe/London'));
        $business = app(CreateSampleBusiness::class)->handle();

        $workingHours = app(CurrentBusiness::class)->run($business, fn (): WorkingHours => $this->workingHoursOf($business));

        $hours = [];
        foreach (CarbonImmutable::parse('2026-11-02')->daysUntil('2026-11-19') as $date) {
            $hours[$date->toDateString()] = $workingHours->on($date)->toArray();
        }
        $this->assertSame([
            '2026-11-02' => self::SPLIT_DAY,
            '2026-11-03' => self::SPLIT_DAY,
            '2026-11-04' => self::SPLIT_DAY,
            '2026-11-05' => self::SPLIT_DAY,
            '2026-11-06' => self::SPLIT_DAY,
            '2026-11-07' => [],
            '2026-11-08' => [],
            '2026-11-09' => self::SPLIT_DAY,
            '2026-11-10' => self::SPLIT_DAY,
            '2026-11-11' => [['10:00', '16:00']],
            '2026-11-12' => [['12:00', '20:00']],
            '2026-11-13' => [],
            '2026-11-14' => [['09:00', '13:00']],
            '2026-11-15' => [],
            '2026-11-16' => self::SPLIT_DAY,
            '2026-11-17' => self::SPLIT_DAY,
            '2026-11-18' => self::SPLIT_DAY,
            '2026-11-19' => self::SPLIT_DAY,
        ], $hours);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function timeOffInEachSeason(): array
    {
        return [
            'in winter, London is on UTC' => ['2026-11-02 08:00', '2026-11-10T14:00:00+00:00', '2026-11-10T17:30:00+00:00'],
            'in summer, London is an hour ahead' => ['2026-07-06 08:00', '2026-07-14T13:00:00+00:00', '2026-07-14T16:30:00+00:00'],
        ];
    }

    #[DataProvider('timeOffInEachSeason')]
    public function test_stores_next_tuesday_afternoons_time_off_in_utc(string $now, string $startsAt, string $endsAt): void
    {
        $this->travelTo(CarbonImmutable::parse($now, 'Europe/London'));
        $business = app(CreateSampleBusiness::class)->handle();

        $timeOff = app(CurrentBusiness::class)->run($business, fn () => $business->practitioners()->sole()->timeOff()->sole());

        $this->assertSame($startsAt, $timeOff->starts_at->toIso8601String());
        $this->assertSame($endsAt, $timeOff->ends_at->toIso8601String());
    }

    public function test_starts_the_pattern_on_this_weeks_monday_in_london_while_it_is_still_sunday_in_utc(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-05 23:30', 'UTC'));

        $business = app(CreateSampleBusiness::class)->handle();

        $pattern = app(CurrentBusiness::class)->run($business, fn () => WorkingPattern::query()->sole());
        $this->assertSame('2026-07-06', $pattern->starts_on->toDateString());
        $this->assertSame('2026-07-06', $pattern->cycle_starts_on->toDateString());
    }

    public function test_called_twice_creates_two_businesses_that_cannot_see_each_others_data(): void
    {
        $first = app(CreateSampleBusiness::class)->handle();
        $second = app(CreateSampleBusiness::class)->handle();

        $firstRows = app(CurrentBusiness::class)->run($first, fn (): array => $this->rowIds());
        $secondRows = app(CurrentBusiness::class)->run($second, fn (): array => $this->rowIds());

        $this->assertNotSame($first->id, $second->id);
        $expectedCounts = ['practitioners' => 1, 'services' => 3, 'working patterns' => 1, 'edited days' => 1, 'time off' => 1];
        $this->assertSame($expectedCounts, array_map(count(...), $firstRows));
        $this->assertSame($expectedCounts, array_map(count(...), $secondRows));
        foreach ($firstRows as $table => $ids) {
            $this->assertSame([], array_values(array_intersect($ids, $secondRows[$table])), "The two businesses share {$table}.");
        }
    }

    public function test_works_with_model_events_turned_off(): void
    {
        $business = Model::withoutEvents(fn (): Business => app(CreateSampleBusiness::class)->handle());

        $counts = [];
        foreach (['practitioners', 'services', 'working_patterns', 'edited_days', 'time_off'] as $table) {
            $counts[$table] = DB::table($table)->where('business_id', $business->id)->count();
        }
        $this->assertSame(
            ['practitioners' => 1, 'services' => 3, 'working_patterns' => 1, 'edited_days' => 1, 'time_off' => 1],
            $counts,
        );
    }

    /**
     * The practitioner's hours, built as the engine's loader (04) will
     * build them.
     */
    private function workingHoursOf(Business $business): WorkingHours
    {
        $practitioner = $business->practitioners()->sole();

        return new WorkingHours(
            $practitioner->workingPatterns->map(fn (WorkingPattern $pattern) => $pattern->toRepeatingPattern())->values()->all(),
            $practitioner->editedDays->mapWithKeys(fn (EditedDay $day): array => [$day->date->toDateString() => $day->hours])->all(),
        );
    }

    /**
     * The IDs of the current business's rows, by table.
     *
     * @return array<string, list<int>>
     */
    private function rowIds(): array
    {
        return [
            'practitioners' => Practitioner::query()->pluck('id')->all(),
            'services' => Service::query()->pluck('id')->all(),
            'working patterns' => WorkingPattern::query()->pluck('id')->all(),
            'edited days' => EditedDay::query()->pluck('id')->all(),
            'time off' => TimeOff::query()->pluck('id')->all(),
        ];
    }
}
