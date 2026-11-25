<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How tightly the business's diary is packed (07): strictness from 0
     * (every time that fits) to 100 (only the least wasteful in each free
     * gap), and the relaxation window, the hours before each time when
     * strictness stops applying, up to 30 days. Existing businesses get the
     * defaults. PostgreSQL adds a column with a constant default without
     * rewriting the table.
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->smallInteger('strictness')->default(50);
            $table->smallInteger('relaxation_hours')->default(48);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE businesses
                ADD CONSTRAINT businesses_strictness_check
                    CHECK (strictness BETWEEN 0 AND 100),
                ADD CONSTRAINT businesses_relaxation_hours_check
                    CHECK (relaxation_hours BETWEEN 0 AND 720)
            SQL);
    }

    /**
     * Dropping the columns drops their checks too.
     */
    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['strictness', 'relaxation_hours']);
        });
    }
};
