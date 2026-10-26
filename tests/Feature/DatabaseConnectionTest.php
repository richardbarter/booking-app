<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards phpunit.xml: tests must run on the PostgreSQL `testing` database,
 * never the development database that RefreshDatabase would wipe.
 */
class DatabaseConnectionTest extends TestCase
{
    public function test_tests_run_on_the_postgresql_testing_database()
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('testing', DB::scalar('select current_database()'));
    }

    public function test_btree_gist_extension_is_available()
    {
        $available = DB::scalar("select count(*) from pg_available_extensions where name = 'btree_gist'");

        $this->assertSame(1, $available);
    }
}
