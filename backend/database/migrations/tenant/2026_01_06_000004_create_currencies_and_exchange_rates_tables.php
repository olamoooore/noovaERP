<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->string('code', 3)->primary(); // USD, EUR, GBP, etc.
            $table->string('name', 100);
            $table->string('symbol', 10)->nullable();
            $table->integer('decimal_places')->default(2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->index('is_active');
        });
        
        // Seed common currencies immediately
        DB::table('currencies')->insert([
            ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2, 'is_active' => DB::raw('true'), 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimal_places' => 2, 'is_active' => DB::raw('true'), 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'GBP', 'name' => 'British Pound', 'symbol' => '£', 'decimal_places' => 2, 'is_active' => DB::raw('true'), 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'MYR', 'name' => 'Malaysian Ringgit', 'symbol' => 'RM', 'decimal_places' => 2, 'is_active' => DB::raw('true'), 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'SGD', 'name' => 'Singapore Dollar', 'symbol' => 'S$', 'decimal_places' => 2, 'is_active' => DB::raw('true'), 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'JPY', 'name' => 'Japanese Yen', 'symbol' => '¥', 'decimal_places' => 0, 'is_active' => DB::raw('true'), 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'CNY', 'name' => 'Chinese Yuan', 'symbol' => '¥', 'decimal_places' => 2, 'is_active' => DB::raw('true'), 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'AUD', 'name' => 'Australian Dollar', 'symbol' => 'A$', 'decimal_places' => 2, 'is_active' => DB::raw('true'), 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'CAD', 'name' => 'Canadian Dollar', 'symbol' => 'C$', 'decimal_places' => 2, 'is_active' => DB::raw('true'), 'created_at' => now(), 'updated_at' => now()],
        ]);
        
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->string('from_currency', 3);
            $table->string('to_currency', 3);
            $table->decimal('rate', 15, 6);
            $table->date('effective_date');
            $table->string('source', 50)->nullable(); // manual, api, bank
            $table->timestamps();
            
            $table->foreign('from_currency')->references('code')->on('currencies');
            $table->foreign('to_currency')->references('code')->on('currencies');
            
            $table->index(['from_currency', 'to_currency']);
            $table->index('effective_date');
        });
        
        // Add currency fields to journal_entry_lines with independent guards to avoid partial-schema drift.
        if (!Schema::hasColumn('journal_entry_lines', 'currency_code')) {
            Schema::table('journal_entry_lines', function (Blueprint $table) {
                $table->string('currency_code', 3)->default('USD')->after('credit');
                $table->foreign('currency_code')->references('code')->on('currencies');
            });
        }

        if (!Schema::hasColumn('journal_entry_lines', 'original_amount')) {
            Schema::table('journal_entry_lines', function (Blueprint $table) {
                $table->decimal('original_amount', 15, 2)->nullable()->after('currency_code');
            });
        }

        if (!Schema::hasColumn('journal_entry_lines', 'exchange_rate')) {
            Schema::table('journal_entry_lines', function (Blueprint $table) {
                $table->decimal('exchange_rate', 15, 6)->default(1.0)->after('original_amount');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('journal_entry_lines', 'currency_code')) {
            Schema::table('journal_entry_lines', function (Blueprint $table) {
                $table->dropForeign(['currency_code']);
                $table->dropColumn(['currency_code', 'original_amount', 'exchange_rate']);
            });
        }
        
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('currencies');
    }
};
