<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lock_dates', function (Blueprint $table) {
            $table->id();
            $table->string('lock_type', 50); // all, sales, purchases, inventory, payroll
            $table->date('lock_date');
            $table->foreignId('set_by')->constrained('users');
            $table->timestamp('set_at')->useCurrent();
            $table->text('reason')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->index('lock_type');
            $table->index('lock_date');
            $table->index('is_active');
        });
        
        Schema::create('lock_date_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->string('transaction_type', 50)->nullable();
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->date('transaction_date');
            $table->text('override_reason');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamps();
            
            $table->index('user_id');
            $table->index('transaction_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lock_date_overrides');
        Schema::dropIfExists('lock_dates');
    }
};
