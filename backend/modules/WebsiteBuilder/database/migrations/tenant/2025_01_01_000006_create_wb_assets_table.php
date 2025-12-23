<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wb_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained('wb_websites')->onDelete('cascade');
            $table->uuid('uuid')->unique();
            $table->string('filename');
            $table->string('path', 500);
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('hash', 64)->nullable(); // SHA256 for dedup
            $table->timestamps();

            $table->index('website_id');
            $table->index('hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wb_assets');
    }
};
