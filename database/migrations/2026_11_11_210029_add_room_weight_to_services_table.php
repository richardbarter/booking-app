<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How much the waste score (06) protects room for each service, from 0
     * (not at all) to 10 (fully). Existing services get 10, so their scores
     * don't change until an owner sets a weight. PostgreSQL adds a column
     * with a constant default without rewriting the table.
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->smallInteger('room_weight')->default(10);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE services
                ADD CONSTRAINT services_room_weight_check
                    CHECK (room_weight BETWEEN 0 AND 10)
            SQL);
    }

    /**
     * Dropping the column drops its check too.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('room_weight');
        });
    }
};
