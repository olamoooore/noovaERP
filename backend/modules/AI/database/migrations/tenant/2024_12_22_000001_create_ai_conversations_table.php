<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->onDelete('cascade');
            $table->string('channel', 20)->default('web'); // web, whatsapp, telegram
            $table->string('external_id')->nullable(); // WhatsApp phone, web session ID
            $table->string('status', 20)->default('active'); // active, closed, transferred
            $table->foreignId('assigned_to')->nullable()->constrained('users')->onDelete('set null');
            $table->string('language', 10)->default('en'); // en, zh, ms
            $table->json('context')->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            
            $table->index('customer_id');
            $table->index('status');
            $table->index('channel');
            $table->index('external_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_conversations');
    }
};
