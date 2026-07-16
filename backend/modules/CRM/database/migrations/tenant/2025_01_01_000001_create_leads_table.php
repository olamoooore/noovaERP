<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('leads')) {
            return;
        }

        Schema::create('leads', function (Blueprint $table) {
            $table->id();

            // Contact info
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('company')->nullable();
            $table->string('job_title')->nullable();

            // Lead tracking
            $table->enum('status', ['new', 'contacted', 'interested', 'proposal', 'won', 'lost'])->default('new');
            $table->enum('source', ['website', 'referral', 'social', 'advertisement', 'cold_call', 'email', 'trade_show', 'other'])->default('other');
            $table->decimal('estimated_value', 15, 2)->nullable();
            $table->integer('score')->default(0); // Lead scoring 0-100

            // Assignment
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('converted_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->timestamp('converted_at')->nullable();

            // Additional
            $table->text('notes')->nullable();
            $table->date('expected_close_date')->nullable();
            $table->date('last_contacted_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('source');
            $table->index('assigned_to');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
