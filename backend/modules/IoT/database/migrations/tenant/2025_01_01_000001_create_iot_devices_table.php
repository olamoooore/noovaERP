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
        Schema::create('iot_devices', function (Blueprint $table) {
            $table->id();
            $table->string('name');                         // e.g., "Counter 1 Printer"
            $table->string('type');                         // printer, terminal, scanner, kiosk
            $table->string('vendor');                       // stripe, escpos, zebra, etc.
            $table->string('connection_type');              // cloud, lan, agent, bluetooth
            $table->text('config');                         // Encrypted connection details (stored as text)
            $table->string('status')->default('offline');   // online, offline, error, maintenance
            $table->timestamp('last_seen_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('type');
            $table->index('status');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('iot_devices');
    }
};
