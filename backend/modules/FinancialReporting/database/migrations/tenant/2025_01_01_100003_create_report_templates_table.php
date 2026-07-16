<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Report Templates Table
     * Stores predefined report configurations for quick access
     */
    public function up(): void
    {
        Schema::create('report_templates', function (Blueprint $table) {
            $table->id();

            // Template identification
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category', 50)->nullable(); // financial, inventory, sales, custom

            // Report configuration
            $table->string('report_type', 50); // profit-loss, balance-sheet, etc.
            $table->jsonb('default_filters'); // Default ReportFilter configuration
            $table->jsonb('chart_config')->nullable(); // Chart display configuration
            $table->jsonb('column_config')->nullable(); // Table column configuration

            // Template settings
            $table->boolean('is_system')->default(false); // System templates can't be deleted
            $table->boolean('is_public')->default(false); // Available to all users
            $table->boolean('is_favorite')->default(false); // User's favorite
            $table->integer('usage_count')->default(0);

            // Display settings
            $table->string('icon', 50)->nullable(); // Icon identifier
            $table->string('color', 20)->nullable(); // Color theme
            $table->integer('sort_order')->default(0);

            // Ownership
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('set null');

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('report_type');
            $table->index('category');
            $table->index('is_system');
            $table->index('is_public');
            $table->index('created_by');
            $table->index(['is_public', 'is_system']); // For listing public/system templates
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_templates');
    }
};
