<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_service_work_orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('type_id')->nullable();
            $table->enum('status', [
                'draft', 'scheduled', 'in_progress', 'on_hold', 'completed', 'invoiced', 'cancelled',
            ])->default('draft');
            $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('site_name', 255)->nullable();
            $table->text('site_address')->nullable();
            $table->decimal('site_lat', 10, 7)->nullable();
            $table->decimal('site_lng', 10, 7)->nullable();
            $table->dateTime('scheduled_start')->nullable();
            $table->dateTime('scheduled_end')->nullable();
            $table->dateTime('actual_start')->nullable();
            $table->dateTime('actual_end')->nullable();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->unsignedBigInteger('lead_technician_id')->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->unsignedBigInteger('sales_order_id')->nullable();
            $table->text('internal_notes')->nullable();
            $table->tinyInteger('customer_rating')->unsigned()->nullable();
            $table->text('customer_feedback')->nullable();
            $table->boolean('requires_signature')->default(true);
            $table->dateTime('technician_signed_at')->nullable();
            $table->dateTime('customer_signed_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('priority');
            $table->index('scheduled_start');
            $table->index('customer_id');
            $table->index('lead_technician_id');
            $table->index('type_id');
            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_service_work_orders');
    }
};
