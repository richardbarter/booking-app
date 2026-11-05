<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A customer's appointment with one practitioner for one service, stored
     * as instants in UTC. The practitioner is blocked from blocked_from to
     * blocked_until, which add the service's buffers. The times and price
     * are copied from the service when booked, so changing the service later
     * never moves a booking or changes what it cost.
     *
     * The exclusion constraint refuses two bookings for one practitioner
     * whose blocked times overlap, unless one is cancelled. Times that only
     * touch don't overlap ('[)'), as in the engine. The practitioner,
     * service and customer can't be deleted while they have bookings (NO
     * ACTION); deleting the business still removes everything, since NO
     * ACTION waits until the end of the delete.
     */
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('practitioner_id');
            $table->foreignId('service_id');
            $table->foreignId('customer_id');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->timestampTz('blocked_from');
            $table->timestampTz('blocked_until');
            $table->string('customer_name', 100);
            $table->string('customer_phone', 30);
            $table->integer('price_pence');
            $table->string('status', 20)->default('confirmed');
            $table->string('source', 20);
            $table->timestamps();

            $table->foreign(['business_id', 'practitioner_id'])
                ->references(['business_id', 'id'])
                ->on('practitioners')
                ->noActionOnDelete();
            $table->foreign(['business_id', 'service_id'])
                ->references(['business_id', 'id'])
                ->on('services')
                ->noActionOnDelete();
            $table->foreign(['business_id', 'customer_id'])
                ->references(['business_id', 'id'])
                ->on('customers')
                ->noActionOnDelete();
            $table->index(['business_id', 'customer_id']);
            $table->index(['business_id', 'service_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE bookings
                ADD CONSTRAINT bookings_ends_at_check
                    CHECK (starts_at < ends_at),
                ADD CONSTRAINT bookings_blocked_time_check
                    CHECK (blocked_from <= starts_at AND blocked_until >= ends_at),
                ADD CONSTRAINT bookings_price_pence_check
                    CHECK (price_pence >= 0),
                ADD CONSTRAINT bookings_status_check
                    CHECK (status IN ('confirmed', 'cancelled', 'no_show')),
                ADD CONSTRAINT bookings_source_check
                    CHECK (source IN ('playground', 'booking_page', 'widget', 'admin')),
                ADD CONSTRAINT bookings_no_overlap
                    EXCLUDE USING gist (
                        practitioner_id WITH =,
                        tstzrange(blocked_from, blocked_until, '[)') WITH &&
                    ) WHERE (status <> 'cancelled')
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
