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
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->string('active_module', 50)->default('general')->after('status');
            $table->index('active_module');
        });

        // Create table to track module switches
        Schema::create('ai_conversation_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('ai_conversations')->onDelete('cascade');
            $table->string('module_name', 50);
            $table->timestamp('switched_at')->useCurrent();

            $table->index(['conversation_id', 'switched_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_conversation_modules');

        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->dropIndex(['active_module']);
            $table->dropColumn('active_module');
        });
    }
};
