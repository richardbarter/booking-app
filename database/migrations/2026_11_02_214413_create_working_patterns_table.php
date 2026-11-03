<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A pattern is a cycle of 1 to 4 weeks of hours, in force from starts_on
     * until ends_on (or until replaced, when null). Its weeks count from
     * cycle_starts_on, a Monday, so a pattern that resumes after a temporary
     * one keeps its rhythm. Only one pattern is in force on any date.
     *
     * The value objects in app/Scheduling/Hours check each time range; the
     * database checks only that weeks is an array of 1 to 4 items.
     */
    public function up(): void
    {
        Schema::create('working_patterns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('practitioner_id');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->date('cycle_starts_on');
            $table->jsonb('weeks');
            $table->timestamps();

            $table->foreign(['business_id', 'practitioner_id'])
                ->references(['business_id', 'id'])
                ->on('practitioners')
                ->cascadeOnDelete();
            $table->index(['business_id', 'practitioner_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE working_patterns
                ADD CONSTRAINT working_patterns_ends_on_check
                    CHECK (ends_on IS NULL OR ends_on >= starts_on),
                ADD CONSTRAINT working_patterns_cycle_starts_on_check
                    CHECK (cycle_starts_on <= starts_on),
                ADD CONSTRAINT working_patterns_cycle_starts_on_monday_check
                    CHECK (extract(isodow FROM cycle_starts_on) = 1),
                ADD CONSTRAINT working_patterns_weeks_check
                    CHECK (CASE WHEN jsonb_typeof(weeks) = 'array'
                        THEN jsonb_array_length(weeks) BETWEEN 1 AND 4
                        ELSE false END),
                ADD CONSTRAINT working_patterns_no_overlap
                    EXCLUDE USING gist (
                        practitioner_id WITH =,
                        daterange(starts_on, ends_on, '[]') WITH &&
                    )
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('working_patterns');
    }
};
