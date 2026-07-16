<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('device_id')->nullable();
            $table->string('agent_id');
            $table->string('job_type'); // product_label, barcode_label, receipt, raw_tspl, raw_escpos
            $table->string('status')->default('pending'); // pending, sent, printing, completed, failed
            $table->json('content'); // The print job content/data
            $table->json('metadata')->nullable(); // Additional info (copies, label size, etc.)
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('device_id');
            $table->index('agent_id');
            $table->index('status');
            $table->index('created_at');

            $table->foreign('device_id')
                ->references('id')
                ->on('device_configurations')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_jobs');
    }
};
