<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('timezone', 64)->default('Europe/London');
            $table->smallInteger('slot_interval_minutes')->default(15);
            $table->integer('min_notice_minutes')->default(120);
            $table->smallInteger('max_advance_days')->default(90);
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE businesses
                ADD CONSTRAINT businesses_slot_interval_minutes_check
                    CHECK (slot_interval_minutes IN (10, 15, 20, 30, 60)),
                ADD CONSTRAINT businesses_min_notice_minutes_check
                    CHECK (min_notice_minutes BETWEEN 0 AND 43200),
                ADD CONSTRAINT businesses_max_advance_days_check
                    CHECK (max_advance_days BETWEEN 1 AND 365),
                ADD CONSTRAINT businesses_booking_window_check
                    CHECK (min_notice_minutes < max_advance_days * 1440)
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};
