<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_name');
            $table->string('account_number', 50)->nullable();
            $table->string('bank_name')->nullable();
            $table->string('currency_code', 3)->default('USD');
            $table->foreignId('gl_account_id')->constrained('chart_of_accounts');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->foreign('currency_code')->references('code')->on('currencies');
            $table->index('is_active');
        });
        
        // Update bank_reconciliations table to reference bank_accounts
        if (Schema::hasTable('bank_reconciliations')) {
            Schema::table('bank_reconciliations', function (Blueprint $table) {
                if (!Schema::hasColumn('bank_reconciliations', 'bank_account_id')) {
                    $table->foreignId('bank_account_id')->nullable()->after('id')->constrained('bank_accounts');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('bank_reconciliations')) {
            Schema::table('bank_reconciliations', function (Blueprint $table) {
                if (Schema::hasColumn('bank_reconciliations', 'bank_account_id')) {
                    $table->dropForeign(['bank_account_id']);
                    $table->dropColumn('bank_account_id');
                }
            });
        }
        
        Schema::dropIfExists('bank_accounts');
    }
};
