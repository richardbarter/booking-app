<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An edited day's hours replace the pattern's for that one date. An empty
     * array means the practitioner isn't working that day.
     */
    public function up(): void
    {
        Schema::create('edited_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('practitioner_id');
            $table->date('date');
            $table->jsonb('hours');
            $table->timestamps();

            $table->foreign(['business_id', 'practitioner_id'])
                ->references(['business_id', 'id'])
                ->on('practitioners')
                ->cascadeOnDelete();
            $table->unique(['practitioner_id', 'date']);
            $table->index(['business_id', 'practitioner_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE edited_days
                ADD CONSTRAINT edited_days_hours_check
                    CHECK (jsonb_typeof(hours) = 'array')
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('edited_days');
    }
};
