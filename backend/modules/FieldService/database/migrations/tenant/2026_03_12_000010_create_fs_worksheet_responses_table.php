<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fs_worksheet_responses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('work_order_id')->unique();  // one per WO
            $table->unsignedBigInteger('template_id');
            $table->json('responses');                              // { field_id: value }
            $table->longText('technician_signature')->nullable();   // base64 PNG
            $table->longText('customer_signature')->nullable();     // base64 PNG
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->string('pdf_path', 500)->nullable();
            $table->timestamps();

            $table->foreign('work_order_id')->references('id')->on('field_service_work_orders')->onDelete('cascade');
            $table->foreign('template_id')->references('id')->on('fs_worksheet_templates')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fs_worksheet_responses');
    }
};
