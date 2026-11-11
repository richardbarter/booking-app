<?php

namespace Tests\Feature\Actions;

use App\Actions\CreateBooking;
use App\Actions\CreateSampleBusiness;
use App\Enums\BookingSource;
use App\Services\CurrentBusiness;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Shows that CreateBooking takes the practitioner's row lock, the lock that
 * makes two bookings for one diary take turns. Its rows are committed,
 * rather than kept in RefreshDatabase's transaction, so a second connection
 * can see and lock them, and the tables are emptied again afterwards so no
 * other test sees them.
 *
 * The second connection holds FOR SHARE, which blocks CreateBooking's FOR
 * UPDATE but not the FOR KEY SHARE that the booking's foreign key check
 * takes on the same row. With FOR UPDATE, that check would wait too, and
 * the test would pass even if CreateBooking took no lock of its own.
 *
 * It also checks that none of AvailabilityService's diary queries ran
 * before the lock timed out, so the lock comes before the check. Plain
 * SELECTs aren't blocked by FOR SHARE, so a check made first would run and
 * then time out at the lock, and the timeout alone wouldn't show the order.
 */
class CreateBookingLockTest extends TestCase
{
    use DatabaseTruncation;

    private const string SECOND_CONNECTION = 'pgsql_second';

    protected function tearDown(): void
    {
        DB::purge(self::SECOND_CONNECTION);
        DB::statement('RESET lock_timeout');
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    public function test_waits_for_the_practitioners_lock_before_checking_the_time(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00', 'Europe/London'));
        $business = app(CreateSampleBusiness::class)->handle();
        app(CurrentBusiness::class)->set($business);
        $assessment = $business->services()->where('name', 'Physio assessment')->sole();
        config(['database.connections.'.self::SECOND_CONNECTION => config('database.connections.pgsql')]);
        $second = DB::connection(self::SECOND_CONNECTION);
        $second->beginTransaction();
        $second->select('SELECT id FROM practitioners WHERE business_id = ? FOR SHARE', [$business->id]);
        DB::statement("SET lock_timeout = '500ms'");
        $queries = [];
        DB::beforeExecuting(function (string $query) use (&$queries): void {
            $queries[] = $query;
        });

        try {
            app(CreateBooking::class)->handle(
                $business,
                $assessment,
                CarbonImmutable::parse('2026-11-09 10:00', 'Europe/London'),
                ['name' => 'Sam Taylor', 'email' => 'sam@example.com', 'phone' => '07700 900123'],
                BookingSource::Admin,
            );
            $this->fail('The booking was saved while another connection held the practitioner\'s lock.');
        } catch (QueryException $exception) {
            $this->assertSame('55P03', $exception->getCode());
        } finally {
            $second->rollBack();
        }

        $this->assertSame([], preg_grep('/"(services|working_patterns|edited_days|time_off|bookings)"/', $queries), 'The diary was read before the practitioner was locked.');
        $this->assertSame(0, DB::table('bookings')->count());
        $this->assertSame(0, DB::table('customers')->count());
    }
}
