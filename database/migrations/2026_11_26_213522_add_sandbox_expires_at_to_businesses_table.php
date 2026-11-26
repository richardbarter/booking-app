<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a playground sandbox ends (08): null for every real business. The
     * partial index serves the clean-up, which looks for expired sandboxes,
     * and leaves real businesses out of it.
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->timestampTz('sandbox_expires_at')->nullable();
        });

        DB::statement(<<<'SQL'
            CREATE INDEX businesses_sandbox_expires_at_index
                ON businesses (sandbox_expires_at)
                WHERE sandbox_expires_at IS NOT NULL
            SQL);
    }

    /**
     * Dropping the column drops its index too.
     */
    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('sandbox_expires_at');
        });
    }
};
