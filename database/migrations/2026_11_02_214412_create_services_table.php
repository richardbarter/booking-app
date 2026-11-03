<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lengths and buffers are whole minutes in 5-minute steps, and prices
     * are in pence. The unique (business_id, id) key is for bookings (05) to
     * reference, so a booking can't point at another business's service.
     */
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->smallInteger('duration_minutes');
            $table->smallInteger('buffer_before_minutes')->default(0);
            $table->smallInteger('buffer_after_minutes')->default(0);
            $table->integer('price_pence');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['business_id', 'id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE services
                ADD CONSTRAINT services_duration_minutes_check
                    CHECK (duration_minutes BETWEEN 5 AND 480 AND duration_minutes % 5 = 0),
                ADD CONSTRAINT services_buffer_before_minutes_check
                    CHECK (buffer_before_minutes BETWEEN 0 AND 120 AND buffer_before_minutes % 5 = 0),
                ADD CONSTRAINT services_buffer_after_minutes_check
                    CHECK (buffer_after_minutes BETWEEN 0 AND 120 AND buffer_after_minutes % 5 = 0),
                ADD CONSTRAINT services_price_pence_check
                    CHECK (price_pence >= 0)
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
