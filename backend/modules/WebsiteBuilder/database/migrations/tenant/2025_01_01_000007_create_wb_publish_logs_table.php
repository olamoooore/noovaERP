<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wb_publish_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained('wb_websites')->onDelete('cascade');
            $table->integer('version')->default(1);
            $table->string('output_path', 500)->nullable();
            $table->enum('status', ['pending', 'success', 'failed'])->default('pending');
            $table->foreignId('published_by')->constrained('users');
            $table->timestamp('published_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('website_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wb_publish_logs');
    }
};
