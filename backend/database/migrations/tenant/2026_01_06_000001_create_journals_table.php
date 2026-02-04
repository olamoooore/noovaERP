<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journals', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->string('type', 50); // Sales, Purchases, Cash, Bank, General
            $table->foreignId('default_debit_account_id')->nullable()->constrained('chart_of_accounts');
            $table->foreignId('default_credit_account_id')->nullable()->constrained('chart_of_accounts');
            $table->string('sequence_prefix', 10)->nullable();
            $table->integer('sequence_number')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->index('type');
            $table->index('is_active');
        });
        
        // Add journal_id to journal_entries table
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreignId('journal_id')->nullable()->after('id')->constrained('journals');
            $table->foreignId('reversed_entry_id')->nullable()->after('journal_id')->constrained('journal_entries');
            $table->boolean('is_reversal')->default(false)->after('reversed_entry_id');
            
            $table->index('journal_id');
            $table->index('reversed_entry_id');
            $table->index('is_reversal');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropForeign(['journal_id']);
            $table->dropForeign(['reversed_entry_id']);
            $table->dropColumn(['journal_id', 'reversed_entry_id', 'is_reversal']);
        });
        
        Schema::dropIfExists('journals');
    }
};
