<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('crm_activities')) {
            return;
        }
        
        Schema::create('crm_activities', function (Blueprint $table) {
            $table->id();
            
            // Lead relation
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            
            // Activity details
            $table->enum('type', ['call', 'email', 'meeting', 'task', 'note'])->default('note');
            $table->string('subject');
            $table->text('description')->nullable();
            $table->timestamp('activity_date')->nullable();
            $table->integer('duration_minutes')->nullable();
            $table->string('outcome')->nullable();
            
            // User tracking
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            
            $table->timestamps();
            
            $table->index('lead_id');
            $table->index('type');
            $table->index('activity_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_activities');
    }
};
