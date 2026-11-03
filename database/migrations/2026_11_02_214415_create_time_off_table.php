<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Time off is stored as instants in UTC. Periods may overlap. The reason
     * is for the business only and never shown to customers.
     */
    public function up(): void
    {
        Schema::create('time_off', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('practitioner_id');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('reason', 255)->nullable();
            $table->timestamps();

            $table->foreign(['business_id', 'practitioner_id'])
                ->references(['business_id', 'id'])
                ->on('practitioners')
                ->cascadeOnDelete();
            $table->index(['business_id', 'practitioner_id', 'starts_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE time_off
                ADD CONSTRAINT time_off_ends_at_check
                    CHECK (ends_at > starts_at)
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('time_off');
    }
};
