<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Someone who has booked with a business, with no login. They're
     * identified within the business by email, stored trimmed and in lower
     * case, so there's one customer per email per business. The name and
     * phone are as given with the first booking.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('email', 254);
            $table->string('phone', 30);
            $table->timestamps();

            $table->unique(['business_id', 'email']);
            $table->unique(['business_id', 'id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE customers
                ADD CONSTRAINT customers_email_check
                    CHECK (email = lower(email) AND email <> '')
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
