<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->string('asset_number', 50)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category', 100)->nullable();

            // Purchase information
            $table->date('purchase_date');
            $table->decimal('purchase_cost', 15, 2);
            $table->foreignId('vendor_id')->nullable()->constrained('vendors');
            $table->string('invoice_reference', 100)->nullable();

            // Depreciation settings
            $table->string('depreciation_method', 50); // Straight-line, Declining Balance, Units of Production
            $table->integer('useful_life_years')->nullable();
            $table->integer('useful_life_units')->nullable();
            $table->decimal('salvage_value', 15, 2)->default(0);
            $table->date('depreciation_start_date')->nullable();

            // Accounts
            $table->foreignId('asset_account_id')->constrained('chart_of_accounts');
            $table->foreignId('depreciation_account_id')->constrained('chart_of_accounts');
            $table->foreignId('accumulated_depreciation_account_id')->constrained('chart_of_accounts');

            // Tracking
            $table->string('location')->nullable();
            $table->foreignId('department_id')->nullable()->constrained('departments');
            $table->string('responsible_person')->nullable();
            $table->string('serial_number', 100)->nullable();

            // Status
            $table->string('status', 50)->default('active'); // active, disposed, sold
            $table->date('disposal_date')->nullable();
            $table->decimal('disposal_amount', 15, 2)->nullable();
            $table->string('disposal_method', 50)->nullable(); // sold, scrapped, donated

            // Calculated fields
            $table->decimal('accumulated_depreciation', 15, 2)->default(0);
            $table->decimal('book_value', 15, 2)->nullable();
            $table->date('last_depreciation_date')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('status');
            $table->index('category');
            $table->index('department_id');
            $table->index('is_active');
        });

        Schema::create('depreciation_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('fixed_assets')->onDelete('cascade');
            $table->date('period_date');
            $table->decimal('depreciation_amount', 15, 2);
            $table->decimal('accumulated_depreciation', 15, 2);
            $table->decimal('book_value', 15, 2);
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries');
            $table->boolean('is_posted')->default(false);
            $table->timestamps();

            $table->index('asset_id');
            $table->index('period_date');
            $table->index('is_posted');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depreciation_schedules');
        Schema::dropIfExists('fixed_assets');
    }
};
