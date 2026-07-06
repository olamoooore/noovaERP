<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_notification_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('event', 100)->index();
            $table->string('channel', 20)->default('mail');
            $table->boolean('mail_enabled')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'event', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_notification_preferences');
    }
};
