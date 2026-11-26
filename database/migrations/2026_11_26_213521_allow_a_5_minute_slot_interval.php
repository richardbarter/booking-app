<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A slot interval can also be 5 minutes (08). The column's default
     * stays 15, and every existing row already passes the new check.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE businesses
                DROP CONSTRAINT businesses_slot_interval_minutes_check,
                ADD CONSTRAINT businesses_slot_interval_minutes_check
                    CHECK (slot_interval_minutes IN (5, 10, 15, 20, 30, 60))
            SQL);
    }

    /**
     * Moves any business on 5 minutes to 15, the default, so the old check
     * can't fail. Until 10's settings screen, only playground sandboxes can
     * be on 5.
     */
    public function down(): void
    {
        DB::statement(<<<'SQL'
            UPDATE businesses SET slot_interval_minutes = 15 WHERE slot_interval_minutes = 5
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE businesses
                DROP CONSTRAINT businesses_slot_interval_minutes_check,
                ADD CONSTRAINT businesses_slot_interval_minutes_check
                    CHECK (slot_interval_minutes IN (10, 15, 20, 30, 60))
            SQL);
    }
};
