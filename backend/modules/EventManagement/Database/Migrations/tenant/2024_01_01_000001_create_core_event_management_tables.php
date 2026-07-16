<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Create events table
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->dateTime('event_date');
            $table->string('venue');
            $table->integer('total_capacity');
            $table->integer('available_capacity');
            $table->enum('status', ['draft', 'published', 'sold_out', 'cancelled'])->default('draft');
            $table->text('terms_conditions')->nullable();
            $table->timestamps();

            // Indexes for performance
            $table->index('event_date');
            $table->index('status');
        });

        // Create event_ticket_types table
        Schema::create('event_ticket_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->onDelete('cascade');
            $table->string('name');
            $table->decimal('price', 10, 2);
            $table->integer('capacity');
            $table->integer('available_capacity');
            $table->text('description')->nullable();
            $table->timestamps();

            // Indexes for performance
            $table->index('event_id');
        });

        // Create bookings table
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('booking_reference', 32)->unique();
            $table->foreignId('event_id')->constrained('events')->onDelete('cascade');
            $table->string('buyer_name');
            $table->string('buyer_email');
            $table->string('buyer_phone', 20);
            $table->decimal('total_amount', 10, 2);
            $table->string('currency', 3);
            $table->enum('state', ['new', 'pending', 'confirmed', 'booked', 'cancelled'])->default('new');
            $table->string('payment_reference')->nullable();
            $table->boolean('consent_pdpa')->default(false);
            $table->boolean('consent_marketing')->default(false);
            $table->timestamps();

            // Indexes for performance
            $table->index('booking_reference');
            $table->index('state');
            $table->index('event_id');
        });

        // Create booking_attendees table
        Schema::create('booking_attendees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->onDelete('cascade');
            $table->foreignId('ticket_type_id')->constrained('event_ticket_types')->onDelete('cascade');
            $table->string('attendee_name');
            $table->string('attendee_email')->nullable();
            $table->string('attendee_phone', 20)->nullable();
            $table->text('encrypted_id_info')->nullable();
            $table->timestamps();

            // Indexes for performance
            $table->index('booking_id');
        });

        // Create tickets table
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_code', 64)->unique();
            $table->foreignId('booking_id')->constrained('bookings')->onDelete('cascade');
            $table->foreignId('attendee_id')->constrained('booking_attendees')->onDelete('cascade');
            $table->text('qr_payload');
            $table->enum('status', ['active', 'revoked', 'used'])->default('active');
            $table->timestamp('issued_at')->useCurrent();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            // Indexes for performance
            $table->index('booking_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('booking_attendees');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('event_ticket_types');
        Schema::dropIfExists('events');
    }
};
