<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('event', 100)->index();
            $table->string('channel', 20)->default('mail');
            $table->string('subject', 255);
            $table->text('body')->nullable();
            $table->json('data')->nullable();
            $table->string('status', 20)->default('pending'); // pending, sent, failed
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'event']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
